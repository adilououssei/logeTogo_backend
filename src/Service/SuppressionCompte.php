<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\ConversationRepository;
use App\Repository\JetonRenouvellementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Suppression définitive d'un compte (exigée par Google Play) : le compte, ses annonces
 * et tous ses fichiers. Le reste (favoris, messages, avis, alertes, notifications…)
 * est effacé par MySQL lui-même (onDelete: 'CASCADE').
 */
class SuppressionCompte
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly JetonRenouvellementRepository $jetonsRenouvellement,
        private readonly Filesystem $systemeFichiers,
        #[Autowire('%app.dossier_public%')]
        private readonly string $dossierPublic,
        #[Autowire('%kernel.project_dir%/var/verifications')]
        private readonly string $dossierVerifications,
    ) {
    }

    public function supprimer(Utilisateur $utilisateur): void
    {
        // 1. Les dossiers à effacer, notés tant que les identifiants existent encore.
        $dossiers = [
            \sprintf('%s/uploads/avatars/%d', $this->dossierPublic, $utilisateur->getId()),
            \sprintf('%s/%d', $this->dossierVerifications, $utilisateur->getId()),
        ];
        foreach ($utilisateur->getAnnonces() as $annonce) {
            $dossiers[] = \sprintf('%s/uploads/annonces/%d', $this->dossierPublic, $annonce->getId());
        }
        foreach ($this->conversations->trouverPourUtilisateur($utilisateur) as $conversation) {
            $dossiers[] = \sprintf('%s/uploads/messages/%d', $this->dossierPublic, $conversation->getId());
        }

        // 2. La base : les annonces d'abord (aucune suppression automatique n'est prévue pour elles),
        //    puis l'utilisateur. Les connexions ouvertes sur ses autres appareils sont fermées.
        foreach ($utilisateur->getAnnonces() as $annonce) {
            $this->em->remove($annonce);
        }
        $this->jetonsRenouvellement->createQueryBuilder('j')
            ->delete()
            ->andWhere('j.username = :identifiant')
            ->setParameter('identifiant', $utilisateur->getUserIdentifier())
            ->getQuery()
            ->execute();
        $this->em->remove($utilisateur);
        $this->em->flush();

        // 3. Les fichiers, seulement maintenant que la base a accepté la suppression.
        $this->systemeFichiers->remove($dossiers);
    }
}
