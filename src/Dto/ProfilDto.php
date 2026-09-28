<?php

namespace App\Dto;

use App\Enum\Region;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modification du profil (PUT /api/profil) : tous les champs du formulaire sont envoyés.
 * L'identifiant de connexion ne change pas, même si le nom change.
 */
final readonly class ProfilDto
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

        /** Vide ou null : l'email est retiré. */
        #[Assert\Email(message: 'Email invalide.')]
        #[Assert\Length(max: 180)]
        public ?string $email = null,

        /** Vide ou null : le téléphone est retiré. */
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
