<?php

namespace App\Dto;

use App\Enum\Region;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Critères de recherche des annonces (paramètres d'URL de GET /api/annonces).
 * Seuls les biens disponibles sont listés : loués, vendus ou à confirmer n'apparaissent jamais.
 * Ex. /api/annonces?region=maritime&typeBien=chambre&prixMax=30000&page=2
 *     /api/annonces?latitude=6.17&longitude=1.23&tri=proches
 */
final readonly class FiltresAnnoncesDto
{
    public function __construct(
        /** Texte cherché dans le titre, la ville et le quartier. */
        #[Assert\Length(max: 100)]
        public ?string $recherche = null,
        public ?Region $region = null,
        #[Assert\Length(max: 100)]
        public ?string $quartier = null,
        public ?TypeBien $typeBien = null,
        public ?TypeTransaction $typeTransaction = null,
        #[Assert\PositiveOrZero]
        public ?int $prixMin = null,
        #[Assert\Positive]
        public ?int $prixMax = null,
        /** Région à afficher en premier (celle de l'utilisateur), sans exclure les autres. */
        public ?Region $regionPrioritaire = null,
        /**
         * Position de l'utilisateur, pour le tri « proches ».
         * Prise en compte pour les utilisateurs connectés seulement (le GPS des biens leur est réservé).
         */
        #[Assert\Range(min: -90, max: 90)]
        public ?float $latitude = null,
        #[Assert\Range(min: -180, max: 180)]
        public ?float $longitude = null,
        /** « recents » (par défaut) ou « proches » (position requise). */
        #[Assert\Choice(choices: ['recents', 'proches'])]
        public string $tri = 'recents',
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 50)]
        public int $parPage = 20,
    ) {
    }
}
