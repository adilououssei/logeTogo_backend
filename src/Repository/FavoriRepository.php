<?php

namespace App\Repository;

use App\Entity\Annonce;
use App\Entity\Favori;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Favori>
 */
class FavoriRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favori::class);
    }

    /**
     * Annonces mises en favori par l'utilisateur, les plus récemment ajoutées d'abord.
     * Les annonces suspendues par la modération sont écartées.
     *
     * @return list<Annonce>
     */
    public function trouverAnnoncesFavorites(Utilisateur $utilisateur): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('a', 'm', 'p')
            ->from(Annonce::class, 'a')
            ->join(Favori::class, 'f', 'WITH', 'f.annonce = a AND f.utilisateur = :utilisateur')
            ->leftJoin('a.medias', 'm')
            ->join('a.publiePar', 'p')
            ->andWhere('a.statut != :suspendu')
            ->setParameter('utilisateur', $utilisateur)
            ->setParameter('suspendu', StatutAnnonce::SUSPENDU)
            ->orderBy('f.dateAjout', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function trouver(Utilisateur $utilisateur, Annonce $annonce): ?Favori
    {
        return $this->findOneBy(['utilisateur' => $utilisateur, 'annonce' => $annonce]);
    }
}
