<?php

namespace App\Security;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion — et l'usage d'un jeton déjà délivré — pour un compte
 * suspendu par un administrateur.
 */
class VerificateurCompte implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Utilisateur && !$user->isEstActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a été suspendu. Contactez le support LogeTogo.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
