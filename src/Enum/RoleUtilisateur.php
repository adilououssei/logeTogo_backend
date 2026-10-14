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
            // Plus de distinction entre les comptes : tout le monde peut publier (anciens rôles conservés en base).
            self::LOCATAIRE, self::PROPRIETAIRE, self::AGENT => 'Membre',
            self::ADMIN => 'Administrateur',
        };
    }

    /** Rôle Symfony correspondant, utilisé par la sécurité (#[IsGranted('ROLE_AGENT')], voters…). */
    public function roleSymfony(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    /**
     * Tout compte connecté peut publier des annonces (comme sur CoinAfrique) : plus de choix
     * « locataire / propriétaire / agent » à l'inscription. Les anciens rôles restent en base,
     * sans effet sur les droits.
     */
    public function peutPublier(): bool
    {
        return true;
    }
}
