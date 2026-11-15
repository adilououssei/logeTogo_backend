<?php

namespace App\Dto;

use App\Enum\Region;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/auth/apple : jeton d'identité Apple, et pour un nouveau compte
 * les informations de l'écran « Compléter votre profil » (région obligatoire).
 * Apple ne donne le nom qu'à l'application, une seule fois : elle le renvoie ici.
 */
final readonly class ConnexionAppleDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Jeton Apple manquant.')]
        public string $jeton = '',

        /** Nouveau compte seulement ; sans région, la réponse demande de compléter le profil. */
        public ?Region $region = null,

        #[Assert\Length(max: 100)]
        public ?string $prenom = null,

        #[Assert\Length(max: 100)]
        public ?string $nom = null,

        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro de téléphone invalide.')]
        public ?string $telephone = null,

        #[Assert\Regex(pattern: '/^\+[0-9]{1,4}$/', message: 'Indicatif pays invalide.')]
        public ?string $indicatifPays = null,

        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro WhatsApp invalide.')]
        public ?string $whatsapp = null,
    ) {
    }
}
