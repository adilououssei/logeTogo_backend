<?php

namespace App\Dto;

use App\Enum\MotifSignalement;
use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/annonces/{id}/signalements : {"motif": "arnaque", "commentaire": "..."} */
final readonly class SignalementDto
{
    public function __construct(
        #[Assert\NotNull(message: 'Choisissez la raison du signalement.')]
        public ?MotifSignalement $motif = null,

        #[Assert\Length(max: 500, maxMessage: 'Le commentaire ne doit pas dépasser {{ limit }} caractères.')]
        public ?string $commentaire = null,
    ) {
    }
}
