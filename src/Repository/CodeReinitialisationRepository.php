<?php

namespace App\Repository;

use App\Entity\CodeReinitialisation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CodeReinitialisation>
 */
class CodeReinitialisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeReinitialisation::class);
    }

    /** Le dernier code demandé par l'utilisateur (les précédents sont supprimés à chaque demande). */
    public function trouverPour(Utilisateur $utilisateur): ?CodeReinitialisation
    {
        return $this->findOneBy(['utilisateur' => $utilisateur], ['id' => 'DESC']);
    }

    public function supprimerPour(Utilisateur $utilisateur): void
    {
        $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.utilisateur = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->getQuery()
            ->execute();
    }
}
