<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/conversations : {"annonceId": 12} — contacter l'auteur de cette annonce. */
final readonly class NouvelleConversationDto
{
    public function __construct(
        #[Assert\NotNull(message: 'L\'annonce est requise.')]
        #[Assert\Positive]
        public ?int $annonceId = null,
    ) {
    }
}
