<?php

namespace App\Repository;

use App\Entity\Appareil;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appareil>
 */
class AppareilRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appareil::class);
    }

    /**
     * Jetons push de plusieurs utilisateurs à la fois.
     *
     * @param list<Utilisateur> $utilisateurs
     *
     * @return list<Appareil>
     */
    public function trouverPourUtilisateurs(array $utilisateurs): array
    {
        if ([] === $utilisateurs) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.utilisateur', 'u')
            ->andWhere('a.utilisateur IN (:utilisateurs)')
            ->setParameter('utilisateurs', $utilisateurs)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les jetons qu'Expo déclare invalides (application désinstallée…).
     *
     * @param list<string> $jetons
     */
    public function supprimerJetons(array $jetons): void
    {
        if ([] === $jetons) {
            return;
        }
        $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.jetonPush IN (:jetons)')
            ->setParameter('jetons', $jetons)
            ->getQuery()
            ->execute();
    }
}
