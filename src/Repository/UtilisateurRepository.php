<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /** Normalise une saisie d'identifiant : « Kofi.Mensah  » → « kofi.mensah ». */
    public static function normaliserIdentifiant(string $saisie): string
    {
        return mb_strtolower(trim($saisie));
    }

    /**
     * Utilisé par la sécurité (connexion, jeton JWT, mot de passe oublié) : l'utilisateur
     * saisit son identifiant (« kofi.mensah ») ou son email, aux majuscules et espaces près.
     * Un identifiant ne contient jamais « @ » : les deux ne peuvent pas se confondre.
     */
    public function loadUserByIdentifier(string $identifier): ?Utilisateur
    {
        $saisie = self::normaliserIdentifiant($identifier);

        return $this->findOneBy(str_contains($saisie, '@') ? ['email' => $saisie] : ['identifiant' => $saisie]);
    }

    /** Re-hache automatiquement le mot de passe quand l'algorithme évolue. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $utilisateur, string $nouveauMotDePasseHache): void
    {
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $utilisateur::class));
        }

        $utilisateur->setMotDePasse($nouveauMotDePasseHache);
        $this->getEntityManager()->flush();
    }

    /**
     * Identifiants déjà pris commençant par $base (« kofi.mensah », « kofi.mensah2 »…),
     * pour générer le prochain identifiant libre à l'inscription.
     *
     * @return list<string>
     */
    public function trouverIdentifiantsCommencantPar(string $base): array
    {
        return $this->createQueryBuilder('u')
            ->select('u.identifiant')
            ->where('u.identifiant LIKE :base')
            ->setParameter('base', addcslashes($base, '%_').'%')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
