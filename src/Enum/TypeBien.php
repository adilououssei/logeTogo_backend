<?php

namespace App\Enum;

enum TypeBien: string
{
    case CHAMBRE = 'chambre';
    case STUDIO = 'studio';
    case APPARTEMENT = 'appartement';
    case MAISON = 'maison';
    case VILLA = 'villa';
    case BUREAU = 'bureau';
    case TERRAIN = 'terrain';
}
