<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/auth/mot-de-passe-oublie/reinitialiser : {"identifiant", "code", "nouveauMotDePasse"} */
final readonly class ReinitialisationMotDePasseDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre identifiant.')]
        #[Assert\Length(max: 180)]
        public string $identifiant = '',

        #[Assert\NotBlank(message: 'Saisissez le code reçu par email.')]
        #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code contient 6 chiffres.')]
        public string $code = '',

        #[Assert\NotBlank(message: 'Le nouveau mot de passe est requis.')]
        #[Assert\Length(min: 6, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
        public string $nouveauMotDePasse = '',
    ) {
    }
}
