<?php

namespace App\Enum;

/** Les cinq régions administratives du Togo, du sud au nord. */
enum Region: string
{
    case MARITIME = 'maritime';
    case PLATEAUX = 'plateaux';
    case CENTRALE = 'centrale';
    case KARA = 'kara';
    case SAVANES = 'savanes';

    public function libelle(): string
    {
        return ucfirst($this->value);
    }

    public function chefLieu(): string
    {
        return match ($this) {
            self::MARITIME => 'Lomé',
            self::PLATEAUX => 'Atakpamé',
            self::CENTRALE => 'Sokodé',
            self::KARA => 'Kara',
            self::SAVANES => 'Dapaong',
        };
    }
}
