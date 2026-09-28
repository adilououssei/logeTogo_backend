<?php

namespace App\Dto;

use App\Enum\StatutAnnonce;
use Symfony\Component\Validator\Constraints as Assert;

/** PATCH /api/annonces/{id}/statut : {"statut": "occupe"} */
final readonly class ChangementStatutDto
{
    public function __construct(
        #[Assert\NotNull(message: 'Le statut est requis.')]
        public ?StatutAnnonce $statut = null,
    ) {
    }
}
