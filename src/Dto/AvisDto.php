<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Avis sur un logement ou un agent : {"note": 4, "commentaire": "Agent sérieux"} */
final readonly class AvisDto
{
    public function __construct(
        #[Assert\NotNull(message: 'Choisissez une note.')]
        #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'La note doit être comprise entre {{ min }} et {{ max }}.')]
        public ?int $note = null,

        #[Assert\Length(max: 1000, maxMessage: 'Le commentaire est trop long ({{ limit }} caractères au plus).')]
        public ?string $commentaire = null,
    ) {
    }
}
