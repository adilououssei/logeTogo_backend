<?php

namespace App\Dto;

/** PATCH /api/profil/preferences : seules les préférences envoyées sont modifiées. */
final readonly class PreferencesDto
{
    public function __construct(
        /** Recevoir aussi par email les nouvelles annonces de ses alertes de recherche. */
        public ?bool $alertesEmail = null,
    ) {
    }
}
