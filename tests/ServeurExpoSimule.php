<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Remplace le service push d'Expo pendant les tests : garde les notifications
 * « envoyées » et répond comme Expo. Un jeton contenant « INVALIDE » reçoit
 * l'erreur DeviceNotRegistered (application désinstallée).
 */
final class ServeurExpoSimule
{
    /** @var list<array<string, mixed>> */
    public static array $envois = [];

    public static function vider(): void
    {
        self::$envois = [];
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $methode, string $url, array $options): ResponseInterface
    {
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
