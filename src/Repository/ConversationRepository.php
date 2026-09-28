<?php

namespace App\Repository;

use App\Entity\Annonce;
use App\Entity\Conversation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Conversations où l'utilisateur est demandeur ou annonceur, la plus récemment active d'abord.
     *
     * @return list<Conversation>
     */
    public function trouverPourUtilisateur(Utilisateur $utilisateur): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a', 'd', 'n')
            ->leftJoin('c.annonce', 'a')
            ->join('c.demandeur', 'd')
            ->join('c.annonceur', 'n')
            ->andWhere('c.demandeur = :utilisateur OR c.annonceur = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->addSelect('COALESCE(c.dateDernierMessage, c.dateCreation) AS HIDDEN activite')
            ->orderBy('activite', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vrai si $demandeur a réellement échangé avec $annonceur (au moins un message),
     * éventuellement à propos d'une annonce précise : condition pour laisser un avis.
     */
    public function aEchange(Utilisateur $demandeur, Utilisateur $annonceur, ?Annonce $annonce = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.demandeur = :demandeur')
            ->andWhere('c.annonceur = :annonceur')
            ->andWhere('c.dateDernierMessage IS NOT NULL')
            ->setParameter('demandeur', $demandeur)
            ->setParameter('annonceur', $annonceur);
        if (null !== $annonce) {
            $qb->andWhere('c.annonce = :annonce')->setParameter('annonce', $annonce);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Conversation déjà ouverte par ce demandeur à propos de cette annonce, s'il y en a une. */
    public function trouverExistante(Annonce $annonce, Utilisateur $demandeur): ?Conversation
    {
        return $this->findOneBy(['annonce' => $annonce, 'demandeur' => $demandeur, 'annonceur' => $annonce->getPubliePar()]);
    }
}
