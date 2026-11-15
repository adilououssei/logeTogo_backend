<?php

namespace App\Tests;

use App\Service\VerificateurJetonApple;
use App\Service\VerificateurJetonGoogle;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Remplace les services HTTP externes pendant les tests :
 *  - Expo (push) : garde les notifications « envoyées » ; un jeton contenant « INVALIDE »
 *    reçoit l'erreur DeviceNotRegistered (application désinstallée) ;
 *  - Ollama (IA) : répond $reponseIa, ou ne répond pas si null ;
 *  - Whisper (transcription) : répond $transcription ;
 *  - Google (certificats publics) : sert le certificat de test, qui valide les jetons de jetonGoogle().
 */
final class ServeurExpoSimule
{
    /** @var list<array<string, mixed>> */
    public static array $envois = [];

    /** @var array<string, mixed>|null champs renvoyés par l'IA ; null = IA en panne */
    public static ?array $reponseIa = null;

    public static string $transcription = '';

    public static int $appelsIa = 0;

    private const KID_GOOGLE = 'cle-test';
    private const DOSSIER_GOOGLE = __DIR__.'/Fixtures/google';

    /**
     * Jeton d'identité « Google » signé par la clé de test, dont le certificat est servi
     * à la place de ceux de Google. $revendications remplace les valeurs par défaut.
     *
     * @param array<string, mixed> $revendications
     */
    public static function jetonGoogle(array $revendications = [], string $cle = 'cle-privee.pem'): string
    {
        $r = $revendications + [
            'iss' => 'https://accounts.google.com', 'aud' => 'client-test.apps.googleusercontent.com',
            'sub' => '1234567890', 'email' => 'ama.koffi@gmail.com', 'email_verified' => true,
            'given_name' => 'Ama', 'family_name' => 'Koffi', 'exp' => new \DateTimeImmutable('+1 hour'),
        ];
        $jeton = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->withHeader('kid', self::KID_GOOGLE)
            ->issuedBy($r['iss'])
            ->permittedFor($r['aud'])
            ->relatedTo($r['sub'])
            ->issuedAt(new \DateTimeImmutable('-1 minute'))
            ->expiresAt($r['exp']);
        foreach (['email', 'email_verified', 'given_name', 'family_name'] as $nom) {
            $jeton = $jeton->withClaim($nom, $r[$nom]);
        }

        $chemin = str_contains($cle, '/') ? $cle : self::DOSSIER_GOOGLE.'/'.$cle;

        return $jeton->getToken(new Sha256(), InMemory::file($chemin))->toString();
    }

    /**
     * Jeton d'identité « Apple » signé par la clé de test (la même que pour Google), dont la clé
     * publique est servie à la place de celles d'Apple. Apple ne met pas le nom dans le jeton.
     *
     * @param array<string, mixed> $revendications
     */
    public static function jetonApple(array $revendications = [], string $cle = 'cle-privee.pem'): string
    {
        $r = $revendications + [
            'iss' => 'https://appleid.apple.com', 'aud' => 'com.exemple.test',
            'sub' => '000123.abcdef.4567', 'email' => 'ama.koffi@gmail.com', 'email_verified' => 'true',
            'exp' => new \DateTimeImmutable('+1 hour'),
        ];
        $jeton = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->withHeader('kid', self::KID_GOOGLE)
            ->issuedBy($r['iss'])
            ->permittedFor($r['aud'])
            ->relatedTo($r['sub'])
            ->issuedAt(new \DateTimeImmutable('-1 minute'))
            ->expiresAt($r['exp'])
            ->withClaim('email', $r['email'])
            ->withClaim('email_verified', $r['email_verified']);

        $chemin = str_contains($cle, '/') ? $cle : self::DOSSIER_GOOGLE.'/'.$cle;

        return $jeton->getToken(new Sha256(), InMemory::file($chemin))->toString();
    }

    /** Clés publiques d'Apple (format JWK) : celle de test, issue du certificat de test. */
    private static function clesApple(): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public(file_get_contents(self::DOSSIER_GOOGLE.'/certificat.pem')));
        $base64url = static fn (string $octets): string => rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');

        return json_encode(['keys' => [[
            'kty' => 'RSA', 'kid' => self::KID_GOOGLE, 'use' => 'sig', 'alg' => 'RS256',
            'n' => $base64url($details['rsa']['n']), 'e' => $base64url($details['rsa']['e']),
        ]]]);
    }

    public static function vider(): void
    {
        self::$envois = [];
        self::$reponseIa = null;
        self::$transcription = '';
        self::$appelsIa = 0;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $methode, string $url, array $options): ResponseInterface
    {
        if (str_contains($url, '/api/chat')) {
            ++self::$appelsIa;

            return null === self::$reponseIa
                ? new MockResponse('', ['error' => 'Connexion refusée (Ollama arrêté)'])
                : new MockResponse(json_encode(['message' => ['role' => 'assistant', 'content' => json_encode(self::$reponseIa)]]));
        }
        if (str_contains($url, 'whisper')) {
            return new MockResponse(json_encode(['text' => self::$transcription]));
        }
        if (VerificateurJetonGoogle::URL_CERTIFICATS === $url) {
            return new MockResponse(json_encode([self::KID_GOOGLE => file_get_contents(self::DOSSIER_GOOGLE.'/certificat.pem')]));
        }

        if (VerificateurJetonApple::URL_CLES === $url) {
            return new MockResponse(self::clesApple());
        }

        $messages = json_decode($options['body'] ?? '[]', true) ?? [];
        $resultats = [];
        foreach ($messages as $message) {
            self::$envois[] = $message;
            $resultats[] = str_contains((string) $message['to'], 'INVALIDE')
                ? ['status' => 'error', 'message' => 'Appareil inconnu', 'details' => ['error' => 'DeviceNotRegistered']]
                : ['status' => 'ok', 'id' => bin2hex(random_bytes(8))];
        }

        return new MockResponse(json_encode(['data' => $resultats]), ['http_code' => 200]);
    }
}
