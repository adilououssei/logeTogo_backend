<?php

namespace App\Service;

use App\Dto\SignalementDto;
use App\Entity\Annonce;
use App\Entity\Notification;
use App\Entity\Signalement;
use App\Entity\Utilisateur;
use App\Enum\MotifSignalement;
use App\Enum\StatutAnnonce;
use App\Enum\TypeNotification;
use App\Repository\SignalementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Modération : signalements envoyés depuis l'application, décisions prises dans
 * l'interface d'administration (suspendre une annonce ou un compte).
 */
class Moderation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SignalementRepository $signalements,
        private readonly GestionAnnonces $gestionAnnonces,
        private readonly EnvoiPush $push,
        private readonly SuiviDisponibilite $suiviDisponibilite,
    ) {
    }

    public function signaler(Annonce $annonce, Utilisateur $auteur, SignalementDto $donnees): Signalement
    {
        if ($annonce->getPubliePar() === $auteur) {
            throw $this->erreur('motif', 'Vous ne pouvez pas signaler votre propre annonce.');
        }
        if ($this->signalements->dejaSignalee($annonce, $auteur)) {
            throw $this->erreur('motif', 'Vous avez déjà signalé cette annonce. Elle est en cours d\'examen.');
        }

        $commentaire = null !== $donnees->commentaire ? trim($donnees->commentaire) : '';
        $signalement = new Signalement($annonce, $auteur, $donnees->motif, '' !== $commentaire ? $commentaire : null);
        $this->em->persist($signalement);
        $this->em->flush();

        if (MotifSignalement::DEJA_LOUE === $donnees->motif) {
            $this->suiviDisponibilite->signaleDejaLoue($annonce);
        }

        return $signalement;
    }

    /**
     * Suspend l'annonce signalée : elle disparaît du catalogue, son auteur est prévenu
     * et tous les signalements en attente de cette annonce sont clos.
     */
    public function suspendreAnnonce(Annonce $annonce, Utilisateur $administrateur, ?string $motif = null): void
    {
        if (StatutAnnonce::SUSPENDU !== $annonce->getStatut()) {
            // Passe par la gestion des annonces : ceux qui l'avaient en favori sont prévenus.
            $this->gestionAnnonces->changerStatut($annonce, StatutAnnonce::SUSPENDU, $administrateur);
        }
        foreach ($this->signalements->enAttentePour($annonce) as $signalement) {
            $signalement->cloturer(true);
        }
        $this->em->flush();

        $this->prevenir(
            $annonce->getPubliePar(),
            'Annonce suspendue',
            \sprintf('Votre annonce « %s » a été suspendue par la modération%s. Contactez le support LogeTogo pour plus d\'informations.', $annonce->getTitre(), null !== $motif && '' !== $motif ? ' : '.$motif : ''),
            $annonce,
        );
    }

    /** Remet en ligne une annonce suspendue (elle redevient disponible). */
    public function reactiverAnnonce(Annonce $annonce, Utilisateur $administrateur): void
    {
        if (StatutAnnonce::SUSPENDU === $annonce->getStatut()) {
            $this->gestionAnnonces->changerStatut($annonce, StatutAnnonce::DISPONIBLE, $administrateur);
            $this->prevenir($annonce->getPubliePar(), 'Annonce remise en ligne', \sprintf('Votre annonce « %s » est de nouveau visible.', $annonce->getTitre()), $annonce);
        }
    }

    /** Signalement sans fondement : l'annonce reste en ligne. */
    public function rejeter(Signalement $signalement): void
    {
        $signalement->cloturer(false);
        $this->em->flush();
    }

    /** Compte suspendu : plus de connexion ni de renouvellement de session (voir VerificateurCompte). */
    public function changerEtatCompte(Utilisateur $utilisateur, bool $actif): void
    {
        $utilisateur->setEstActif($actif);
        $this->em->flush();
    }

    private function prevenir(?Utilisateur $destinataire, string $titre, string $corps, Annonce $annonce): void
    {
        if (null === $destinataire) {
            return;
        }
        $this->em->persist((new Notification())
            ->setDestinataire($destinataire)
            ->setType(TypeNotification::MISE_A_JOUR_STATUT)
            ->setTitre($titre)
            ->setContenu($corps)
            ->setAnnonce($annonce));
        $this->em->flush();
        $this->push->envoyer([$destinataire], $titre, $corps, ['type' => TypeNotification::MISE_A_JOUR_STATUT->value, 'idAnnonce' => $annonce->getId()]);
    }

    private function erreur(string $champ, string $texte): ValidationFailedException
    {
        return new ValidationFailedException(null, new ConstraintViolationList([
            new ConstraintViolation($texte, $texte, [], null, $champ, null),
        ]));
    }
}
