<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Avis;
use App\Entity\DemandeVerification;
use App\Entity\Favori;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeMessage;
use App\Enum\TypeNotification;
use App\Repository\AlerteRechercheRepository;
use App\Repository\NotificationRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les notifications affichées dans l'application et les envoie en push :
 * - nouveau message reçu ;
 * - nouvelle annonce (tous les utilisateurs ; « pour vous » si une alerte de recherche correspond) ;
 * - changement de statut d'une annonce mise en favori ;
 * - nouvel avis reçu, décision sur une vérification d'identité.
 * Les push des messages s'affichent en bandeau (canal « messages »), comme WhatsApp.
 * Chaque notification part aussi par email, sauf pour qui l'a désactivé (Profil).
 */
class Notificateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly AlerteRechercheRepository $alertes,
        private readonly UtilisateurRepository $utilisateurs,
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
        $apercu = TypeMessage::IMAGE === $message->getType()
            ? '📷 Photo'.('' !== trim((string) $message->getContenu()) ? ' · '.$message->getContenu() : '')
            : (string) $message->getContenu();
        $titre = 'Nouveau message de '.$expediteur->getNomComplet();

        $nonLue = $this->notifications->trouverMessageNonLu($destinataire, $conversation);
        $notification = $nonLue
            ?? (new Notification())
                ->setDestinataire($destinataire)
                ->setType(TypeNotification::MESSAGE)
                ->setTitre($titre)
                ->setConversation($conversation)
                ->setAnnonce($conversation->getAnnonce());
        $notification->rafraichir($apercu);
        $this->em->persist($notification);
        $this->em->flush();

        // Comme WhatsApp : le nom de l'expéditeur en titre, le message (ou « 📷 Photo ») en dessous.
        $this->push->envoyer([$destinataire], $expediteur->getNomComplet(), $apercu, [
            'type' => TypeNotification::MESSAGE->value,
            'idConversation' => $conversation->getId(),
        ], 'messages');

        // Un seul email par conversation tant que le destinataire n'a pas lu : pas un email par message.
        if (null === $nonLue && $destinataire->isAlertesEmail()) {
            $sujet = $conversation->getAnnonce()?->getTitre();
            $this->email->envoyer($destinataire, $titre.' · LogeTogo', \sprintf(
                "%s vous a écrit%s :\n\n« %s »\n\nOuvrez l'application LogeTogo pour lui répondre.",
                $expediteur->getNomComplet(),
                null !== $sujet ? ' à propos de « '.$sujet.' »' : '',
                $apercu,
            ));
        }
    }

    /**
     * Nouvelle annonce : tous les utilisateurs sont prévenus (cloche, push, email), sauf son auteur.
     * Ceux dont une alerte de recherche correspond reçoivent « Nouvelle annonce pour vous ».
     */
    public function nouvelleAnnonce(Annonce $annonce): void
    {
        $pourEux = [];
        foreach ($this->alertes->trouverActivesPourRegion($annonce->getLocalisation()->getRegion()) as $alerte) {
            if ($alerte->correspondA($annonce)) {
                $pourEux[$alerte->getUtilisateur()->getId()] = true;
            }
        }
        $destinataires = array_values(array_filter(
            $this->utilisateurs->findBy(['estActif' => true]),
            fn (Utilisateur $u) => $u !== $annonce->getPubliePar() && RoleUtilisateur::ADMIN !== $u->getRole(),
        ));
        if ([] === $destinataires) {
            return;
        }

        $localisation = $annonce->getLocalisation();
        $prix = number_format($annonce->getPrix(), 0, ',', ' ');
        $corps = \sprintf('%s · %s · %s FCFA', $annonce->getTitre(), $localisation->getQuartier(), $prix);
        $cibles = array_values(array_filter($destinataires, fn (Utilisateur $u) => isset($pourEux[$u->getId()])));
        $autres = array_values(array_filter($destinataires, fn (Utilisateur $u) => !isset($pourEux[$u->getId()])));
        if ([] !== $cibles) {
            $this->creerPourChacun($cibles, TypeNotification::NOUVELLE_ANNONCE, 'Nouvelle annonce pour vous', $corps, $annonce, 'annonces');
        }
        if ([] !== $autres) {
            $this->creerPourChacun($autres, TypeNotification::NOUVELLE_ANNONCE, 'Nouvelle annonce sur LogeTogo', $corps, $annonce, 'annonces');
        }

        foreach ($destinataires as $destinataire) {
            if ($destinataire->isAlertesEmail()) {
                $this->email->envoyer($destinataire, 'Nouvelle annonce : '.$annonce->getTitre(), \sprintf(
                    "%s\n\n%s\n%s, %s · %s FCFA\n\nOuvrez l'application LogeTogo pour la voir et contacter l'annonceur.",
                    isset($pourEux[$destinataire->getId()]) ? 'Une nouvelle annonce correspond à l\'une de vos alertes de recherche :' : 'Une nouvelle annonce vient d\'être publiée :',
                    $annonce->getTitre(), $localisation->getQuartier(), $localisation->getVille(), $prix,
                ));
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
            StatutAnnonce::OCCUPE => 'n\'est plus disponible (louée)',
            StatutAnnonce::VENDU => 'a été vendue',
            StatutAnnonce::A_CONFIRMER, StatutAnnonce::SUSPENDU => 'n\'est plus disponible',
        };
        $corps = \sprintf('« %s » %s.', $annonce->getTitre(), $etat);
        $this->creerPourChacun($destinataires, TypeNotification::MISE_A_JOUR_STATUT, 'Un de vos favoris a changé', $corps, $annonce);
        foreach ($destinataires as $destinataire) {
            if ($destinataire->isAlertesEmail()) {
                $this->email->envoyer($destinataire, 'Un de vos favoris a changé · LogeTogo', $corps);
            }
        }
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
        if ($destinataire->isAlertesEmail()) {
            $this->email->envoyer($destinataire, 'Nouvel avis reçu · LogeTogo', $corps);
        }
    }

    /** @param list<Utilisateur> $destinataires */
    private function creerPourChacun(array $destinataires, TypeNotification $type, string $titre, string $corps, Annonce $annonce, string $canal = 'defaut'): void
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

        $this->push->envoyer($destinataires, $titre, $corps, ['type' => $type->value, 'idAnnonce' => $annonce->getId()], $canal);
    }
}
