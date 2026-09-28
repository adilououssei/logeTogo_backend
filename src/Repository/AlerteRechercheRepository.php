<?php

namespace App\Repository;

use App\Entity\AlerteRecherche;
use App\Entity\Utilisateur;
use App\Enum\Region;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlerteRecherche>
 */
class AlerteRechercheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlerteRecherche::class);
    }

    /**
     * Alertes actives qui peuvent concerner une annonce de cette région :
     * celles de la région et celles « toutes régions » (region NULL).
     *
     * @return list<AlerteRecherche>
     */
    public function trouverActivesPourRegion(Region $region): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.utilisateur', 'u')
            ->andWhere('a.estActive = true')
            ->andWhere('a.region IS NULL OR a.region = :region')
            ->setParameter('region', $region)
            ->getQuery()
            ->getResult();
    }

    /** @return list<AlerteRecherche> */
    public function trouverPourUtilisateur(Utilisateur $utilisateur): array
    {
        return $this->findBy(['utilisateur' => $utilisateur], ['dateCreation' => 'DESC', 'id' => 'DESC']);
    }
}
