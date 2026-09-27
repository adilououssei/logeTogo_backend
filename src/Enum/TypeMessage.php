<?php

namespace App\Enum;

enum TypeMessage: string
{
    case TEXTE = 'texte';
    case VOCAL = 'vocal';
    case IMAGE = 'image';
    case VIDEO = 'video';
    case DOCUMENT = 'document';
}
