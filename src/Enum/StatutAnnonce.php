<?php

namespace App\Enum;

enum StatutAnnonce: string
{
    case DISPONIBLE = 'disponible';
    case OCCUPE = 'occupe';
    case VENDU = 'vendu';
    /** Masquée par la modération (administrateur). */
    case SUSPENDU = 'suspendu';

    /** Seules ces annonces sont visibles dans les listes publiques. */
    public function estVisible(): bool
    {
        return self::SUSPENDU !== $this;
    }
}
