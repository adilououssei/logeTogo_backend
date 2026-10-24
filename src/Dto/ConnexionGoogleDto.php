<?php

namespace App\Dto;

use App\Enum\Region;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/auth/google : jeton d'identité Google, et pour un nouveau compte
 * les informations de l'écran « Compléter votre profil » (région obligatoire).
 */
final readonly class ConnexionGoogleDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Jeton Google manquant.')]
        public string $jeton = '',

        /** Nouveau compte seulement ; sans région, la réponse demande de compléter le profil. */
        public ?Region $region = null,

        /** Nouveau compte : prénom et nom corrigés par l'utilisateur (sinon ceux de Google). */
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
