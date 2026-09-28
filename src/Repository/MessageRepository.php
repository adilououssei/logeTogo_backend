<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Messages d'une conversation, du plus ancien au plus récent. Avec $apresId, seulement
     * ceux arrivés depuis (l'application interroge régulièrement le serveur).
     *
     * @return list<Message>
     */
    public function trouverDansConversation(Conversation $conversation, ?int $apresId = null, int $limite = 200): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversation')
            ->setParameter('conversation', $conversation);
        if (null !== $apresId) {
            $qb->andWhere('m.id > :apres')->setParameter('apres', $apresId);
        }

        // On prend les $limite plus récents, puis on les remet dans l'ordre chronologique.
        $messages = $qb->orderBy('m.id', 'DESC')->setMaxResults($limite)->getQuery()->getResult();

        return array_reverse($messages);
    }

    /** Marque comme lus les messages reçus par $lecteur dans cette conversation. */
    public function marquerLus(Conversation $conversation, Utilisateur $lecteur): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.estLu', 'true')
            ->andWhere('m.conversation = :conversation')
            ->andWhere('m.expediteur != :lecteur')
            ->andWhere('m.estLu = false')
            ->setParameter('conversation', $conversation)
            ->setParameter('lecteur', $lecteur)
            ->getQuery()
            ->execute();
    }

    /**
     * Nombre de messages non lus reçus par l'utilisateur, par conversation.
     *
     * @return array<int, int> id de conversation → nombre
     */
    public function compterNonLusParConversation(Utilisateur $utilisateur): array
    {
        $lignes = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.conversation) AS conversation', 'COUNT(m.id) AS nombre')
            ->join('m.conversation', 'c')
            ->andWhere('c.demandeur = :utilisateur OR c.annonceur = :utilisateur')
            ->andWhere('m.expediteur != :utilisateur')
            ->andWhere('m.estLu = false')
            ->setParameter('utilisateur', $utilisateur)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(fn (array $l) => [(int) $l['conversation'], (int) $l['nombre']], $lignes), 1, 0);
    }

    /**
     * Dernier message de chaque conversation donnée.
     *
     * @param list<int> $idsConversations
     *
     * @return array<int, Message> id de conversation → message
     */
    public function trouverDerniers(array $idsConversations): array
    {
        if ([] === $idsConversations) {
            return [];
        }
        $messages = $this->createQueryBuilder('m')
            ->andWhere('m.id IN (SELECT MAX(m2.id) FROM '.Message::class.' m2 WHERE m2.conversation IN (:ids) GROUP BY m2.conversation)')
            ->setParameter('ids', $idsConversations)
            ->getQuery()
            ->getResult();

        $parConversation = [];
        foreach ($messages as $message) {
            $parConversation[$message->getConversation()->getId()] = $message;
        }

        return $parConversation;
    }
}
