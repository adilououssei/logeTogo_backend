<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Enum\TypeNotification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Notifications de l'utilisateur, les plus récentes d'abord.
     *
     * @return list<Notification>
     */
    public function trouverPourUtilisateur(Utilisateur $utilisateur, int $limite = 50): array
    {
        return $this->createQueryBuilder('n')
            ->addSelect('a', 'c')
            ->leftJoin('n.annonce', 'a')
            ->leftJoin('n.conversation', 'c')
            ->andWhere('n.destinataire = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->orderBy('n.dateCreation', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function compterNonLues(Utilisateur $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :utilisateur')
            ->andWhere('n.estLue = false')
            ->setParameter('utilisateur', $utilisateur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function toutMarquerLu(Utilisateur $utilisateur): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.estLue', 'true')
            ->andWhere('n.destinataire = :utilisateur')
            ->andWhere('n.estLue = false')
            ->setParameter('utilisateur', $utilisateur)
            ->getQuery()
            ->execute();
    }

    /** Notification « nouveau message » encore non lue pour cette conversation (pour la regrouper). */
    public function trouverMessageNonLu(Utilisateur $destinataire, Conversation $conversation): ?Notification
    {
        return $this->findOneBy([
            'destinataire' => $destinataire,
            'conversation' => $conversation,
            'type' => TypeNotification::MESSAGE,
            'estLue' => false,
        ]);
    }

    /** Ouvrir une conversation marque comme lues ses notifications de message. */
    public function marquerLuesPourConversation(Utilisateur $destinataire, Conversation $conversation): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.estLue', 'true')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.conversation = :conversation')
            ->andWhere('n.estLue = false')
            ->setParameter('destinataire', $destinataire)
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->execute();
    }
}
