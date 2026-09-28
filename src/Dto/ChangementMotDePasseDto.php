<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** PUT /api/profil/mot-de-passe : {"motDePasseActuel": "...", "nouveauMotDePasse": "..."} */
final readonly class ChangementMotDePasseDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.')]
        public string $motDePasseActuel = '',

        #[Assert\NotBlank(message: 'Le nouveau mot de passe est requis.')]
        #[Assert\Length(min: 6, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
        #[Assert\NotEqualTo(propertyPath: 'motDePasseActuel', message: 'Le nouveau mot de passe doit être différent de l\'actuel.')]
        public string $nouveauMotDePasse = '',
    ) {
    }
}
