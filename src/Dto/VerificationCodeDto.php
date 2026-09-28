<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** POST /api/auth/mot-de-passe-oublie/verifier : {"identifiant": "...", "code": "123456"} */
final readonly class VerificationCodeDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Saisissez votre identifiant.')]
        #[Assert\Length(max: 180)]
        public string $identifiant = '',

        #[Assert\NotBlank(message: 'Saisissez le code reçu par email.')]
        #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code contient 6 chiffres.')]
        public string $code = '',
    ) {
    }
}
