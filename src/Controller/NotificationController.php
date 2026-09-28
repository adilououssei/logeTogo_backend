<?php

namespace App\Controller;

use App\Dto\AppareilDto;
use App\Entity\Appareil;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Repository\AppareilRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Notifications de l'utilisateur connecté (nouveaux messages, annonces correspondant
 * à ses alertes, changements de statut de ses favoris) et téléphones qui reçoivent les push.
 */
#[Route('/api/notifications', name: 'api_notifications_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class NotificationController extends AbstractController
{
    /** Les 50 dernières notifications, les plus récentes d'abord. */
    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(#[CurrentUser] Utilisateur $utilisateur, NotificationRepository $notifications): JsonResponse
    {
        return $this->json($notifications->trouverPourUtilisateur($utilisateur), context: ['groups' => ['notification:lecture']]);
    }

    /** {"total": 2} — pastille de la cloche. */
    #[Route('/non-lues', name: 'non_lues', methods: ['GET'])]
    public function nonLues(#[CurrentUser] Utilisateur $utilisateur, NotificationRepository $notifications): JsonResponse
    {
        return $this->json(['total' => $notifications->compterNonLues($utilisateur)]);
    }

    #[Route('/tout-lire', name: 'tout_lire', methods: ['POST'])]
    public function toutLire(#[CurrentUser] Utilisateur $utilisateur, NotificationRepository $notifications): Response
    {
        $notifications->toutMarquerLu($utilisateur);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/lue', name: 'lue', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function marquerLue(Notification $notification, #[CurrentUser] Utilisateur $utilisateur, EntityManagerInterface $em): Response
    {
        if ($notification->getDestinataire() !== $utilisateur) {
            throw new NotFoundHttpException('Notification introuvable.');
        }
        $notification->setEstLue(true);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Enregistre le téléphone pour recevoir les push. S'il était associé à un autre
     * compte (changement d'utilisateur sur le même téléphone), il passe à celui-ci.
     */
    #[Route('/appareils', name: 'appareil_enregistrer', methods: ['POST'])]
    public function enregistrerAppareil(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AppareilDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        AppareilRepository $appareils,
        EntityManagerInterface $em,
    ): Response {
        $appareil = $appareils->findOneBy(['jetonPush' => $donnees->jetonPush]) ?? (new Appareil())->setJetonPush($donnees->jetonPush);
        $appareil->setUtilisateur($utilisateur)->setPlateforme($donnees->plateforme)->actualiser();
        $em->persist($appareil);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** À la déconnexion : ce téléphone ne reçoit plus les push de ce compte. */
    #[Route('/appareils', name: 'appareil_retirer', methods: ['DELETE'])]
    public function retirerAppareil(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AppareilDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        AppareilRepository $appareils,
        EntityManagerInterface $em,
    ): Response {
        $appareil = $appareils->findOneBy(['jetonPush' => $donnees->jetonPush, 'utilisateur' => $utilisateur]);
        if (null !== $appareil) {
            $em->remove($appareil);
            $em->flush();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
