<?php

namespace App\Service;

/**
 * Ce que le jeton Apple garantit sur la personne : son compte Apple et son email (si vérifié).
 * Apple ne met jamais le nom dans le jeton : l'application l'envoie à part, à la première connexion.
 */
final readonly class IdentiteApple
{
    public function __construct(
        public string $appleId,
        public ?string $email,
    ) {
    }
}
