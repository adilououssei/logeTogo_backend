<?php

namespace App\Enum;

enum TypeNotification: string
{
    case NOUVELLE_ANNONCE = 'nouvelle_annonce';
    case MESSAGE = 'message';
    case MISE_A_JOUR_STATUT = 'mise_a_jour_statut';
    case AVIS = 'avis';
    case VERIFICATION = 'verification';
    /** Rappel à l'annonceur : son bien est-il toujours disponible ? */
    case DISPONIBILITE = 'disponibilite';
}
