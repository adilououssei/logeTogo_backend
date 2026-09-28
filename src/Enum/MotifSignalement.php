<?php

namespace App\Enum;

/** Raison pour laquelle un utilisateur signale une annonce. */
enum MotifSignalement: string
{
    case ARNAQUE = 'arnaque';
    case FAUSSES_INFORMATIONS = 'fausses_informations';
    case DEJA_LOUE = 'deja_loue';
    case PHOTOS_TROMPEUSES = 'photos_trompeuses';
    case CONTENU_INAPPROPRIE = 'contenu_inapproprie';
    case DOUBLON = 'doublon';
    case AUTRE = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::ARNAQUE => 'Arnaque ou demande d\'argent suspecte',
            self::FAUSSES_INFORMATIONS => 'Prix ou informations faux',
            self::DEJA_LOUE => 'Bien déjà loué ou vendu',
            self::PHOTOS_TROMPEUSES => 'Photos trompeuses',
            self::CONTENU_INAPPROPRIE => 'Contenu inapproprié',
            self::DOUBLON => 'Annonce en double',
            self::AUTRE => 'Autre raison',
        };
    }
}
