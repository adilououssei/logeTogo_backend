<?php

namespace App\Service;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérifie le jeton d'identité (« idToken ») renvoyé à l'application par « Continuer avec Google » :
 * signé par Google (certificats publics, mis en cache), destiné à LogeTogo, non expiré.
 *
 * Pas de bibliothèque Google : le jeton est un JWT standard, vérifié avec lcobucci/jwt
 * (déjà installé pour nos propres jetons de connexion).
 */
class VerificateurJetonGoogle
{
    public const URL_CERTIFICATS = 'https://www.googleapis.com/oauth2/v1/certs';

    /** @var list<string> */
    private readonly array $clientsAutorises;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $horloge,
        /** ID(s) client OAuth « Web » de Google Cloud, séparés par des virgules. */
        #[Autowire(env: 'GOOGLE_CLIENT_IDS')]
        string $clientsAutorises,
    ) {
        $this->clientsAutorises = array_values(array_filter(array_map('trim', explode(',', $clientsAutorises))));
    }

    /** @throws UnauthorizedHttpException si le jeton est invalide */
    public function verifier(string $jeton): IdentiteGoogle
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($jeton);
        } catch (\Throwable) {
            throw $this->refus();
        }
        if (!$token instanceof UnencryptedToken) {
            throw $this->refus();
        }

        $certificat = $this->certificats()[$token->headers()->get('kid')] ?? null;
        $audiences = $token->claims()->get('aud', []);
        $valide = null !== $certificat
            && [] !== array_intersect($this->clientsAutorises, $audiences)
            && (new Validator())->validate(
                $token,
                new SignedWith(new Sha256(), InMemory::plainText($certificat)),
                new IssuedBy('https://accounts.google.com', 'accounts.google.com'),
                new LooseValidAt($this->horloge, new \DateInterval('PT1M')),
            );
        if (!$valide) {
            throw $this->refus();
        }

        $c = $token->claims();
        $email = $c->get('email');

        return new IdentiteGoogle(
            googleId: (string) $c->get('sub'),
            email: \is_string($email) && true === $c->get('email_verified') ? mb_strtolower($email) : null,
            prenom: $c->get('given_name'),
            nom: $c->get('family_name'),
        );
    }

    /**
     * Certificats publics de Google, par identifiant de clé (« kid »).
     * Gardés en cache aussi longtemps que Google l'indique (Cache-Control: max-age).
     *
     * @return array<string, string>
     */
    private function certificats(): array
    {
        return $this->cache->get('google_certificats_oauth2', function (ItemInterface $element): array {
            $reponse = $this->httpClient->request('GET', self::URL_CERTIFICATS);
            $cacheControl = $reponse->getHeaders(false)['cache-control'][0] ?? '';
            $element->expiresAfter(preg_match('/max-age=(\d+)/', $cacheControl, $m) ? (int) $m[1] : 3600);

            return $reponse->toArray();
        });
    }

    private function refus(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException('Bearer', 'Connexion Google refusée. Réessayez.');
    }
}
