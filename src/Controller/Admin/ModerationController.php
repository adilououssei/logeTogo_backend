<?php

namespace App\Controller\Admin;

use App\Entity\DemandeVerification;
use App\Entity\Signalement;
use App\Entity\Utilisateur;
use App\Repository\DemandeVerificationRepository;
use App\Repository\SignalementRepository;
use App\Service\Moderation;
use App\Service\VerificationIdentite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Signalements d'annonces et vérifications d'identité à traiter. */
#[Route('/admin', name: 'admin_')]
class ModerationController extends AbstractController
{
    use JetonAdminTrait;

    #[Route('/signalements', name: 'signalements', methods: ['GET'])]
    public function signalements(Request $requete, SignalementRepository $signalements): Response
    {
        $statut = (string) $requete->query->get('statut', Signalement::EN_ATTENTE);
        $statut = \in_array($statut, [Signalement::EN_ATTENTE, Signalement::TRAITE, Signalement::REJETE], true) ? $statut : null;

        return $this->render('admin/moderation/signalements.html.twig', [
            'signalements' => $signalements->lister($statut),
            'statut' => $statut,
        ]);
    }

    #[Route('/signalements/{id}/suspendre', name: 'signalement_suspendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function suspendre(Signalement $signalement, Request $requete, Moderation $moderation, #[CurrentUser] Utilisateur $admin): Response
    {
        $this->verifierJeton($requete);
        $moderation->suspendreAnnonce($signalement->getAnnonce(), $admin, $signalement->getMotif()->libelle());
        $this->addFlash('succes', \sprintf('Annonce « %s » suspendue ; les signalements la concernant sont clos.', $signalement->getAnnonce()->getTitre()));

        return $this->redirectToRoute('admin_signalements');
    }

    #[Route('/signalements/{id}/rejeter', name: 'signalement_rejeter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejeter(Signalement $signalement, Request $requete, Moderation $moderation): Response
    {
        $this->verifierJeton($requete);
        $moderation->rejeter($signalement);
        $this->addFlash('succes', 'Signalement rejeté : l\'annonce reste en ligne.');

        return $this->redirectToRoute('admin_signalements');
    }

    #[Route('/verifications', name: 'verifications', methods: ['GET'])]
    public function verifications(DemandeVerificationRepository $demandes): Response
    {
        return $this->render('admin/moderation/verifications.html.twig', [
            'enAttente' => $demandes->enAttente(),
            'traitees' => $demandes->findBy(['statut' => [DemandeVerification::APPROUVEE, DemandeVerification::REFUSEE]], ['dateDecision' => 'DESC'], 20),
            'types' => DemandeVerification::TYPES_DOCUMENT,
        ]);
    }

    /** Photo d'une pièce d'identité : servie uniquement aux administrateurs, jamais mise en cache. */
    #[Route('/verifications/{id}/photo/{face}', name: 'verification_photo', requirements: ['id' => '\d+', 'face' => 'recto|verso'], methods: ['GET'])]
    public function photo(DemandeVerification $demande, string $face, VerificationIdentite $verification): Response
    {
        $chemin = 'recto' === $face ? $demande->getRecto() : $demande->getVerso();
        if (null === $chemin || !is_file($verification->cheminAbsolu($chemin))) {
            throw $this->createNotFoundException('Photo supprimée ou absente.');
        }
        $reponse = new BinaryFileResponse($verification->cheminAbsolu($chemin));
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    #[Route('/verifications/{id}/decision', name: 'verification_decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decider(DemandeVerification $demande, Request $requete, VerificationIdentite $verification): Response
    {
        $this->verifierJeton($requete);
        if (!$demande->estEnAttente()) {
            $this->addFlash('erreur', 'Cette demande a déjà été traitée.');

            return $this->redirectToRoute('admin_verifications');
        }
        $approuvee = 'approuver' === $requete->request->get('decision');
        $motif = trim((string) $requete->request->get('motif', ''));
        if (!$approuvee && '' === $motif) {
            $this->addFlash('erreur', 'Indiquez le motif du refus : il sera montré à l\'utilisateur.');

            return $this->redirectToRoute('admin_verifications');
        }
        $verification->decider($demande, $approuvee, $approuvee ? null : $motif);
        $this->addFlash('succes', \sprintf(
            '%s : %s. L\'utilisateur a été prévenu et les photos ont été supprimées.',
            $demande->getUtilisateur()->getNomComplet(),
            $approuvee ? 'identité vérifiée' : 'demande refusée',
        ));

        return $this->redirectToRoute('admin_verifications');
    }
}
