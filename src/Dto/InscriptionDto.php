<?php

namespace App\Dto;

use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use Symfony\Component\Validator\Constraints as Assert;

/** Données envoyées par l'application pour créer un compte (POST /api/auth/inscription). */
final readonly class InscriptionDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le prénom est requis.')]
        #[Assert\Length(max: 100)]
        public string $prenom = '',

        #[Assert\NotBlank(message: 'Le nom est requis.')]
        #[Assert\Length(max: 100)]
        public string $nom = '',

        #[Assert\NotNull(message: 'La région est requise.')]
        public ?Region $region = null,

        #[Assert\NotBlank(message: 'Le mot de passe est requis.')]
        #[Assert\Length(min: 6, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
        public string $motDePasse = '',

        /** Locataire par défaut ; le rôle administrateur ne peut pas être choisi à l'inscription. */
        #[Assert\Choice(
            choices: [RoleUtilisateur::LOCATAIRE, RoleUtilisateur::PROPRIETAIRE, RoleUtilisateur::AGENT],
            message: 'Ce type de compte n\'est pas autorisé.',
        )]
        public RoleUtilisateur $role = RoleUtilisateur::LOCATAIRE,

        #[Assert\Email(message: 'Email invalide.')]
        #[Assert\Length(max: 180)]
        public ?string $email = null,

        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro de téléphone invalide.')]
        public ?string $telephone = null,

        #[Assert\Regex(pattern: '/^\+[0-9]{1,4}$/', message: 'Indicatif pays invalide.')]
        public ?string $indicatifPays = null,

        /** Numéro WhatsApp ; vide ou null : pas de WhatsApp. */
        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro WhatsApp invalide.')]
        public ?string $whatsapp = null,
    ) {
    }
}
