<?php

namespace App\Repository;

use App\Entity\Annonce;
use App\Entity\Signalement;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Signalement>
 */
class SignalementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signalement::class);
    }

    public function dejaSignalee(Annonce $annonce, Utilisateur $auteur): bool
    {
        return null !== $this->findOneBy(['annonce' => $annonce, 'auteur' => $auteur, 'statut' => Signalement::EN_ATTENTE]);
    }

    /** @return list<Signalement> signalements en attente de cette annonce */
    public function enAttentePour(Annonce $annonce): array
    {
        return $this->findBy(['annonce' => $annonce, 'statut' => Signalement::EN_ATTENTE]);
    }

    public function compterEnAttente(): int
    {
        return $this->count(['statut' => Signalement::EN_ATTENTE]);
    }

    /** @return list<Signalement> les plus récents d'abord, avec l'annonce et l'auteur */
    public function lister(?string $statut, int $limite = 100): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('a', 'u', 'p')
            ->join('s.annonce', 'a')
            ->join('s.auteur', 'u')
            ->join('a.publiePar', 'p')
            ->orderBy('s.dateCreation', 'DESC')
            ->setMaxResults($limite);
        if (null !== $statut) {
            $qb->andWhere('s.statut = :statut')->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }
}
