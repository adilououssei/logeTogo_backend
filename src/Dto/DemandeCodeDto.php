<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/auth/mot-de-passe-oublie : {"identifiant": "kofi.mensah"} */
final readonly class DemandeCodeDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre identifiant.')]
        #[Assert\Length(max: 180)]
        public string $identifiant = '',
    ) {
    }
}
