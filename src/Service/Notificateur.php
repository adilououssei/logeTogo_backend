<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Avis;
use App\Entity\DemandeVerification;
use App\Entity\Favori;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeMessage;
use App\Enum\TypeNotification;
use App\Repository\AlerteRechercheRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les notifications affichées dans l'application et les envoie en push :
 * - nouveau message reçu ;
 * - nouvelle annonce correspondant à une alerte de recherche ;
 * - changement de statut d'une annonce mise en favori ;
 * - nouvel avis reçu, décision sur une vérification d'identité.
 * Les alertes de recherche sont aussi envoyées par email à qui l'a accepté.
 */
class Notificateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly AlerteRechercheRepository $alertes,
        private readonly EnvoiPush $push,
        private readonly EnvoiEmail $email,
    ) {
    }

    /**
     * Nouveau message : tant que le destinataire n'a pas lu la notification de cette
     * conversation, on la met à jour au lieu d'en créer une par message.
     */
    public function nouveauMessage(Message $message): void
    {
        $conversation = $message->getConversation();
        $expediteur = $message->getExpediteur();
        $destinataire = $conversation->getAutreParticipant($expediteur);
        $apercu = TypeMessage::IMAGE === $message->getType() ? 'Photo' : (string) $message->getContenu();
        $titre = 'Nouveau message de '.$expediteur->getNomComplet();

        $notification = $this->notifications->trouverMessageNonLu($destinataire, $conversation)
            ?? (new Notification())
                ->setDestinataire($destinataire)
                ->setType(TypeNotification::MESSAGE)
                ->setTitre($titre)
                ->setConversation($conversation)
                ->setAnnonce($conversation->getAnnonce());
        $notification->rafraichir($apercu);
        $this->em->persist($notification);
        $this->em->flush();

        $this->push->envoyer([$destinataire], $titre, $apercu, [
            'type' => TypeNotification::MESSAGE->value,
            'idConversation' => $conversation->getId(),
        ]);
    }

    /** Nouvelle annonce : prévient les utilisateurs dont une alerte active correspond. */
    public function nouvelleAnnonce(Annonce $annonce): void
    {
        $alertes = $this->alertes->trouverActivesPourRegion($annonce->getLocalisation()->getRegion());

        $destinataires = [];
        foreach ($alertes as $alerte) {
            $utilisateur = $alerte->getUtilisateur();
            if ($utilisateur !== $annonce->getPubliePar() && $utilisateur->isEstActif() && $alerte->correspondA($annonce)) {
                $destinataires[$utilisateur->getId()] = $utilisateur;
            }
        }
        if ([] === $destinataires) {
            return;
        }

        $titre = 'Nouvelle annonce pour vous';
        $corps = \sprintf('%s · %s · %s FCFA', $annonce->getTitre(), $annonce->getLocalisation()->getQuartier(), number_format($annonce->getPrix(), 0, ',', ' '));
        $this->creerPourChacun(array_values($destinataires), TypeNotification::NOUVELLE_ANNONCE, $titre, $corps, $annonce);

        $localisation = $annonce->getLocalisation();
        $texte = \sprintf(
            "Une nouvelle annonce correspond à l'une de vos alertes de recherche :\n\n%s\n%s, %s · %s FCFA\n\nOuvrez l'application LogeTogo pour la voir et contacter l'annonceur.",
            $annonce->getTitre(), $localisation->getQuartier(), $localisation->getVille(), number_format($annonce->getPrix(), 0, ',', ' '),
        );
        foreach ($destinataires as $destinataire) {
            if ($destinataire->isAlertesEmail()) {
                $this->email->envoyer($destinataire, 'Nouvelle annonce : '.$annonce->getTitre(), $texte);
            }
        }
    }

    /** Vérification d'identité acceptée ou refusée : l'utilisateur est prévenu (application, push, email). */
    public function decisionVerification(DemandeVerification $demande): void
    {
        $utilisateur = $demande->getUtilisateur();
        [$titre, $corps] = $demande->estApprouvee()
            ? ['Identité vérifiée', 'Votre identité a été vérifiée. Le badge « vérifié » apparaît désormais sur votre profil et vos annonces.']
            : ['Vérification refusée', 'Votre demande de vérification a été refusée'.(null !== $demande->getMotifRefus() ? ' : '.$demande->getMotifRefus() : '.').' Vous pouvez envoyer une nouvelle demande.'];

        $this->em->persist((new Notification())
            ->setDestinataire($utilisateur)
            ->setType(TypeNotification::VERIFICATION)
            ->setTitre($titre)
            ->setContenu($corps));
        $this->em->flush();

        $this->push->envoyer([$utilisateur], $titre, $corps, ['type' => TypeNotification::VERIFICATION->value]);
        $this->email->envoyer($utilisateur, $titre.' · LogeTogo', $corps);
    }

    /** L'annonce devient occupée, vendue ou à nouveau disponible : prévient ceux qui l'ont en favori. */
    public function changementStatut(Annonce $annonce): void
    {
        $destinataires = array_map(
            fn (Favori $f) => $f->getUtilisateur(),
            $this->em->getRepository(Favori::class)->findBy(['annonce' => $annonce]),
        );
        if ([] === $destinataires) {
            return;
        }

        $etat = match ($annonce->getStatut()) {
            StatutAnnonce::DISPONIBLE => 'est de nouveau disponible',
            StatutAnnonce::OCCUPE => 'n\'est plus disponible (occupée)',
            StatutAnnonce::VENDU => 'a été vendue',
            StatutAnnonce::SUSPENDU => 'a été retirée',
        };
        $this->creerPourChacun($destinataires, TypeNotification::MISE_A_JOUR_STATUT, 'Un de vos favoris a changé', \sprintf('« %s » %s.', $annonce->getTitre(), $etat), $annonce);
    }

    /** Nouvel avis : l'agent ou le propriétaire évalué (ou l'auteur de l'annonce notée) est prévenu. */
    public function nouvelAvis(Avis $avis): void
    {
        $annonce = $avis->getAnnonce();
        $destinataire = $avis->getAgentEvalue() ?? $annonce?->getPubliePar();
        if (null === $destinataire) {
            return;
        }
        $auteur = $avis->getAuteur();
        $sujet = null !== $annonce ? \sprintf('« %s »', $annonce->getTitre()) : 'votre travail';
        $corps = \sprintf('%s %s. a noté %s %d/5', $auteur->getPrenom(), mb_substr((string) $auteur->getNom(), 0, 1), $sujet, $avis->getNote())
            .(null !== $avis->getCommentaire() ? ' : '.$avis->getCommentaire() : '.');

        $this->em->persist((new Notification())
            ->setDestinataire($destinataire)
            ->setType(TypeNotification::AVIS)
            ->setTitre('Nouvel avis reçu')
            ->setContenu($corps)
            ->setAnnonce($annonce));
        $this->em->flush();

        $this->push->envoyer([$destinataire], 'Nouvel avis reçu', $corps, [
            'type' => TypeNotification::AVIS->value,
            'idAnnonce' => $annonce?->getId(),
        ]);
    }

    /** @param list<Utilisateur> $destinataires */
    private function creerPourChacun(array $destinataires, TypeNotification $type, string $titre, string $corps, Annonce $annonce): void
    {
        foreach ($destinataires as $destinataire) {
            $this->em->persist((new Notification())
                ->setDestinataire($destinataire)
                ->setType($type)
                ->setTitre($titre)
                ->setContenu($corps)
                ->setAnnonce($annonce));
        }
        $this->em->flush();

        $this->push->envoyer($destinataires, $titre, $corps, ['type' => $type->value, 'idAnnonce' => $annonce->getId()]);
    }
}
