<?php

namespace App\Enum;

enum StatutAnnonce: string
{
    case DISPONIBLE = 'disponible';
    case OCCUPE = 'occupe';
    case VENDU = 'vendu';
    /**
     * Disponibilité non confirmée par l'annonceur (rappel resté sans réponse, ou plusieurs
     * locataires ont signalé le bien comme déjà loué) : retirée du public jusqu'à confirmation.
     */
    case A_CONFIRMER = 'a_confirmer';
    /** Masquée par la modération (administrateur). */
    case SUSPENDU = 'suspendu';

    public function libelle(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::OCCUPE => 'Loué',
            self::VENDU => 'Vendu',
            self::A_CONFIRMER => 'À confirmer',
            self::SUSPENDU => 'Suspendu',
        };
    }

    public function estVisible(): bool
    {
        return self::SUSPENDU !== $this;
    }
}
