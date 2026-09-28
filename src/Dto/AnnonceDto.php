<?php

namespace App\Dto;

use App\Enum\Region;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contenu d'une annonce envoyé par l'agent ou le propriétaire
 * (POST /api/annonces pour publier, PUT /api/annonces/{id} pour modifier).
 * Les photos et vidéos sont envoyées ensuite via /api/annonces/{id}/medias.
 */
final readonly class AnnonceDto
{
    /**
     * @param list<string> $equipements
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Le titre est requis.')]
        #[Assert\Length(max: 150)]
        public string $titre = '',

        #[Assert\NotBlank(message: 'La description est requise.')]
        #[Assert\Length(max: 5000)]
        public string $description = '',

        #[Assert\NotNull(message: 'Le type de bien est requis.')]
        public ?TypeBien $typeBien = null,

        #[Assert\NotNull(message: 'Le type de transaction est requis.')]
        public ?TypeTransaction $typeTransaction = null,

        /** Loyer mensuel (location) ou prix total (vente), en FCFA. */
        #[Assert\NotNull(message: 'Le prix est requis.')]
        #[Assert\Positive(message: 'Le prix doit être supérieur à 0.')]
        public ?int $prix = null,

        #[Assert\Range(min: 0, max: 24)]
        public ?int $avanceMois = null,

        #[Assert\Range(min: 0, max: 24)]
        public ?int $cautionMois = null,

        #[Assert\PositiveOrZero]
        public ?int $commission = null,

        #[Assert\Range(min: 0, max: 50)]
        public int $chambres = 0,

        #[Assert\Range(min: 0, max: 50)]
        public int $sallesDeBain = 0,

        #[Assert\Positive]
        public ?int $superficie = null,

        #[Assert\Count(max: 30)]
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 60)])]
        public array $equipements = [],

        #[Assert\NotNull(message: 'La région est requise.')]
        public ?Region $region = null,

        #[Assert\NotBlank(message: 'La ville est requise.')]
        #[Assert\Length(max: 100)]
        public string $ville = '',

        #[Assert\NotBlank(message: 'Le quartier est requis.')]
        #[Assert\Length(max: 100)]
        public string $quartier = '',

        #[Assert\Length(max: 255)]
        public ?string $adresse = null,

        #[Assert\Range(min: -90, max: 90)]
        public ?float $latitude = null,

        #[Assert\Range(min: -180, max: 180)]
        public ?float $longitude = null,

        /** Numéros propres à l'annonce ; sinon ceux de l'annonceur sont utilisés. */
        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro de téléphone invalide.')]
        public ?string $telephoneContact = null,

        #[Assert\Regex(pattern: '/^[0-9 +().-]{8,30}$/', message: 'Numéro WhatsApp invalide.')]
        public ?string $whatsappContact = null,
    ) {
    }
}
