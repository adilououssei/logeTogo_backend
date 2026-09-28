<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/conversations/{id}/messages : {"contenu": "Bonjour, est-ce encore disponible ?"} */
final readonly class NouveauMessageDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le message est vide.', normalizer: 'trim')]
        #[Assert\Length(max: 5000, maxMessage: 'Le message est trop long ({{ limit }} caractères au plus).')]
        public string $contenu = '',
    ) {
    }
}
