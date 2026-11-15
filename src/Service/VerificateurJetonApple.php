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
 * Vérifie le jeton d'identité (« identityToken ») renvoyé à l'application par « Se connecter avec Apple » :
 * signé par Apple (clés publiques, mises en cache), destiné à LogeTogo, non expiré.
 *
 * Contrairement à Google (certificats PEM), Apple publie ses clés au format JWK (n, e) :
 * elles sont converties en clé publique PEM pour lcobucci/jwt, sans bibliothèque supplémentaire.
 */
class VerificateurJetonApple
{
    public const URL_CLES = 'https://appleid.apple.com/auth/keys';
    public const EMETTEUR = 'https://appleid.apple.com';

    /** @var list<string> */
    private readonly array $clientsAutorises;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $horloge,
        /** Identifiant(s) de bundle de l'application iOS, séparés par des virgules. */
        #[Autowire(env: 'APPLE_CLIENT_IDS')]
        string $clientsAutorises,
    ) {
        $this->clientsAutorises = array_values(array_filter(array_map('trim', explode(',', $clientsAutorises))));
    }

    /** @throws UnauthorizedHttpException si le jeton est invalide */
    public function verifier(string $jeton): IdentiteApple
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($jeton);
        } catch (\Throwable) {
            throw $this->refus();
        }
        if (!$token instanceof UnencryptedToken) {
            throw $this->refus();
        }

        $cle = $this->cles()[$token->headers()->get('kid')] ?? null;
        $audiences = $token->claims()->get('aud', []);
        $valide = null !== $cle
            && [] !== array_intersect($this->clientsAutorises, $audiences)
            && (new Validator())->validate(
                $token,
                new SignedWith(new Sha256(), InMemory::plainText($cle)),
                new IssuedBy(self::EMETTEUR),
                new LooseValidAt($this->horloge, new \DateInterval('PT1M')),
            );
        if (!$valide) {
            throw $this->refus();
        }

        $c = $token->claims();
        $email = $c->get('email');
        // Apple écrit parfois « email_verified » en texte ("true") plutôt qu'en booléen.
        $emailVerifie = true === $c->get('email_verified') || 'true' === $c->get('email_verified');

        return new IdentiteApple(
            appleId: (string) $c->get('sub'),
            email: \is_string($email) && $emailVerifie ? mb_strtolower($email) : null,
        );
    }

    /**
     * Clés publiques d'Apple (PEM), par identifiant de clé (« kid »).
     * Gardées en cache aussi longtemps qu'Apple l'indique (Cache-Control: max-age).
     *
     * @return array<string, string>
     */
    private function cles(): array
    {
        return $this->cache->get('apple_cles_publiques', function (ItemInterface $element): array {
            $reponse = $this->httpClient->request('GET', self::URL_CLES);
            $cacheControl = $reponse->getHeaders(false)['cache-control'][0] ?? '';
            $element->expiresAfter(preg_match('/max-age=(\d+)/', $cacheControl, $m) ? (int) $m[1] : 3600);

            $cles = [];
            foreach ($reponse->toArray()['keys'] ?? [] as $jwk) {
                if ('RSA' === ($jwk['kty'] ?? null) && isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                    $cles[$jwk['kid']] = self::jwkVersPem($jwk['n'], $jwk['e']);
                }
            }

            return $cles;
        });
    }

    /** Clé publique RSA (n, e en base64url) → PEM « PUBLIC KEY » (structure ASN.1 SubjectPublicKeyInfo). */
    public static function jwkVersPem(string $n, string $e): string
    {
        $entier = static function (string $octets): string {
            $octets = ltrim($octets, "\x00");
            if ('' === $octets || ord($octets[0]) > 0x7F) {
                $octets = "\x00".$octets; // entier positif
            }

            return "\x02".self::longueurDer($octets).$octets;
        };
        $sequence = static fn (string $contenu): string => "\x30".self::longueurDer($contenu).$contenu;

        $cle = $sequence($entier(self::base64url($n)).$entier(self::base64url($e)));
        // OID rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algorithme = hex2bin('300d06092a864886f70d0101010500');
        $info = $sequence($algorithme."\x03".self::longueurDer("\x00".$cle)."\x00".$cle);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($info), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function longueurDer(string $contenu): string
    {
        $longueur = \strlen($contenu);
        if ($longueur < 0x80) {
            return \chr($longueur);
        }
        $octets = ltrim(pack('N', $longueur), "\x00");

        return \chr(0x80 | \strlen($octets)).$octets;
    }

    private static function base64url(string $texte): string
    {
        return base64_decode(strtr($texte, '-_', '+/').str_repeat('=', (4 - \strlen($texte) % 4) % 4));
    }

    private function refus(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException('Bearer', 'Connexion Apple refusée. Réessayez.');
    }
}
