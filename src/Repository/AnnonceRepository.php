<?php

namespace App\Repository;

use App\Dto\FiltresAnnoncesDto;
use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Annonce>
 */
class AnnonceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Annonce::class);
    }

    /**
     * Recherche publique : annonces disponibles par défaut (jamais les suspendues),
     * la région prioritaire en premier puis les plus récentes.
     *
     * @return Paginator<Annonce>
     */
    public function rechercher(FiltresAnnoncesDto $filtres): Paginator
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('m', 'p')
            ->leftJoin('a.medias', 'm')
            ->join('a.publiePar', 'p')
            ->andWhere('a.statut = :statut')
            ->setParameter('statut', StatutAnnonce::DISPONIBLE);

        if (null !== $filtres->recherche && '' !== trim($filtres->recherche)) {
            $qb->andWhere('a.titre LIKE :texte OR a.localisation.ville LIKE :texte OR a.localisation.quartier LIKE :texte')
                ->setParameter('texte', '%'.addcslashes(trim($filtres->recherche), '%_').'%');
        }
        if (null !== $filtres->region) {
            $qb->andWhere('a.localisation.region = :region')->setParameter('region', $filtres->region);
        }
        if (null !== $filtres->quartier && '' !== trim($filtres->quartier)) {
            $qb->andWhere('a.localisation.quartier = :quartier')->setParameter('quartier', trim($filtres->quartier));
        }
        if (null !== $filtres->typeBien) {
            $qb->andWhere('a.typeBien = :typeBien')->setParameter('typeBien', $filtres->typeBien);
        }
        if (null !== $filtres->typeTransaction) {
            $qb->andWhere('a.typeTransaction = :typeTransaction')->setParameter('typeTransaction', $filtres->typeTransaction);
        }
        if (null !== $filtres->prixMin) {
            $qb->andWhere('a.prix >= :prixMin')->setParameter('prixMin', $filtres->prixMin);
        }
        if (null !== $filtres->prixMax) {
            $qb->andWhere('a.prix <= :prixMax')->setParameter('prixMax', $filtres->prixMax);
        }

        if (null !== $filtres->regionPrioritaire) {
            $qb->addSelect('CASE WHEN a.localisation.region = :regionPrioritaire THEN 0 ELSE 1 END AS HIDDEN priorite')
                ->setParameter('regionPrioritaire', $filtres->regionPrioritaire)
                ->addOrderBy('priorite', 'ASC');
        }
        $qb->addOrderBy('a.datePublication', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult(($filtres->page - 1) * $filtres->parPage)
            ->setMaxResults($filtres->parPage);

        return new Paginator($qb, fetchJoinCollection: true);
    }

    /**
     * Toutes les annonces d'un agent ou propriétaire (tous statuts), les plus récentes d'abord.
     *
     * @return list<Annonce>
     */
    public function trouverParAnnonceur(Utilisateur $annonceur): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('m')
            ->leftJoin('a.medias', 'm')
            ->andWhere('a.publiePar = :annonceur')
            ->setParameter('annonceur', $annonceur)
            ->orderBy('a.datePublication', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Annonces en ligne dont la disponibilité n'a pas été confirmée depuis la date donnée
     * et pour lesquelles aucun rappel n'est en cours.
     *
     * @return list<Annonce>
     */
    public function sansConfirmationDepuis(\DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.statut = :disponible')
            ->andWhere('a.dateRappelDisponibilite IS NULL')
            ->andWhere('a.dateConfirmation <= :limite')
            ->setParameter('disponible', StatutAnnonce::DISPONIBLE)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Annonces en ligne dont le rappel « toujours disponible ? » est resté sans réponse depuis la date donnée.
     *
     * @return list<Annonce>
     */
    public function rappelSansReponseDepuis(\DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.statut = :disponible')
            ->andWhere('a.dateRappelDisponibilite <= :limite')
            ->setParameter('disponible', StatutAnnonce::DISPONIBLE)
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getResult();
    }
}
