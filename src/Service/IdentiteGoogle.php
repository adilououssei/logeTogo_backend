<?php

namespace App\Service;

/** Ce que le jeton Google garantit sur la personne : son compte Google et son email (si vérifié). */
final readonly class IdentiteGoogle
{
    public function __construct(
        public string $googleId,
        public ?string $email,
        public ?string $prenom,
        public ?string $nom,
    ) {
    }
}
