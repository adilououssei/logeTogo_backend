<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Téléphone à enregistrer pour les push : {"jetonPush": "ExponentPushToken[...]", "plateforme": "android"} */
final readonly class AppareilDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le jeton de notification est requis.')]
        #[Assert\Regex(pattern: '/^Expo(nent)?PushToken\[[^\]]+\]$/', message: 'Jeton de notification invalide.')]
        public string $jetonPush = '',

        #[Assert\Choice(choices: ['android', 'ios'], message: 'Plateforme inconnue.')]
        public string $plateforme = 'android',
    ) {
    }
}
