<?php

namespace App\Security\Voter;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur les annonces :
 * - VOIR      : tout le monde, sauf une annonce suspendue (son auteur et les administrateurs seulement) ;
 * - PUBLIER   : agents, propriétaires et administrateurs ;
 * - MODIFIER  : l'auteur de l'annonce ou un administrateur (y compris photos et statut) ;
 * - SUPPRIMER : idem.
 *
 * @extends Voter<string, Annonce|null>
 */
final class AnnonceVoter extends Voter
{
    public const VOIR = 'ANNONCE_VOIR';
    public const PUBLIER = 'ANNONCE_PUBLIER';
    public const MODIFIER = 'ANNONCE_MODIFIER';
    public const SUPPRIMER = 'ANNONCE_SUPPRIMER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::PUBLIER => true,
            self::VOIR, self::MODIFIER, self::SUPPRIMER => $subject instanceof Annonce,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();
        $connecte = $utilisateur instanceof Utilisateur;
        $estAdmin = $connecte && RoleUtilisateur::ADMIN === $utilisateur->getRole();

        return match ($attribute) {
            self::PUBLIER => $connecte && $utilisateur->getRole()->peutPublier(),
            self::VOIR => StatutAnnonce::SUSPENDU !== $subject->getStatut()
                || $estAdmin
                || ($connecte && $subject->getPubliePar() === $utilisateur),
            self::MODIFIER, self::SUPPRIMER => $estAdmin || ($connecte && $subject->getPubliePar() === $utilisateur),
            default => false,
        };
    }
}
