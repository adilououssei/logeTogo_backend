<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Remplace les services HTTP externes pendant les tests :
 *  - Expo (push) : garde les notifications « envoyées » ; un jeton contenant « INVALIDE »
 *    reçoit l'erreur DeviceNotRegistered (application désinstallée) ;
 *  - Ollama (IA) : répond $reponseIa, ou ne répond pas si null ;
 *  - Whisper (transcription) : répond $transcription.
 */
final class ServeurExpoSimule
{
    /** @var list<array<string, mixed>> */
    public static array $envois = [];

    /** @var array<string, mixed>|null champs renvoyés par l'IA ; null = IA en panne */
    public static ?array $reponseIa = null;

    public static string $transcription = '';

    public static int $appelsIa = 0;

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
