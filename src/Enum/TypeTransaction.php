<?php

namespace App\Enum;

enum TypeTransaction: string
{
    case LOCATION = 'location';
    case VENTE = 'vente';

    public function libelle(): string
    {
        return self::LOCATION === $this ? 'Location' : 'Vente';
    }
}
