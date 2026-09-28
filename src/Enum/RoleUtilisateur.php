<?php

namespace App\Enum;

enum RoleUtilisateur: string
{
    case LOCATAIRE = 'locataire';
    case PROPRIETAIRE = 'proprietaire';
    case AGENT = 'agent';
    case ADMIN = 'admin';

    public function libelle(): string
    {
        return match ($this) {
            self::LOCATAIRE => 'Locataire',
            self::PROPRIETAIRE => 'Propriétaire',
            self::AGENT => 'Agent immobilier',
            self::ADMIN => 'Administrateur',
        };
    }

    /** Rôle Symfony correspondant, utilisé par la sécurité (#[IsGranted('ROLE_AGENT')], voters…). */
    public function roleSymfony(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    /** Les agents et les propriétaires peuvent publier des annonces. */
    public function peutPublier(): bool
    {
        return \in_array($this, [self::AGENT, self::PROPRIETAIRE, self::ADMIN], true);
    }
}
