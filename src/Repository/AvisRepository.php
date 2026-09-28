<?php

namespace App\Repository;

use App\Entity\Annonce;
use App\Entity\Avis;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Avis>
 */
class AvisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Avis::class);
    }

    /**
     * Note moyenne et nombre d'avis visibles, pour plusieurs annonces (ou agents) à la fois.
     *
     * @param 'annonce'|'agentEvalue' $cible
     * @param list<int>               $ids
     *
     * @return array<int, array{moyenne: float, nombre: int}>
     */
    public function statistiques(string $cible, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $colonne = 'annonce' === $cible ? 'v.annonce' : 'v.agentEvalue';

        $lignes = $this->createQueryBuilder('v')
            ->select("IDENTITY($colonne) AS id", 'AVG(v.note) AS moyenne', 'COUNT(v.id) AS nombre')
            ->andWhere("$colonne IN (:ids)")
            ->andWhere('v.estMasque = false')
            ->setParameter('ids', $ids)
            ->groupBy($colonne)
            ->getQuery()
            ->getArrayResult();

        $statistiques = [];
        foreach ($lignes as $ligne) {
            $statistiques[(int) $ligne['id']] = ['moyenne' => (float) $ligne['moyenne'], 'nombre' => (int) $ligne['nombre']];
        }

        return $statistiques;
    }

    /**
     * Avis visibles sur une annonce ou sur un agent, les plus récents d'abord.
     *
     * @return list<Avis>
     */
    public function trouverVisibles(?Annonce $annonce, ?Utilisateur $agent, int $limite = 50): array
    {
        $qb = $this->createQueryBuilder('v')
            ->addSelect('a')
            ->join('v.auteur', 'a')
            ->andWhere('v.estMasque = false')
            ->orderBy('v.dateCreation', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults($limite);
        null !== $annonce
            ? $qb->andWhere('v.annonce = :cible')->setParameter('cible', $annonce)
            : $qb->andWhere('v.agentEvalue = :cible')->setParameter('cible', $agent);

        return $qb->getQuery()->getResult();
    }

    public function trouverDeAuteur(Utilisateur $auteur, ?Annonce $annonce, ?Utilisateur $agent): ?Avis
    {
        return null !== $annonce
            ? $this->findOneBy(['auteur' => $auteur, 'annonce' => $annonce])
            : $this->findOneBy(['auteur' => $auteur, 'agentEvalue' => $agent]);
    }
}
