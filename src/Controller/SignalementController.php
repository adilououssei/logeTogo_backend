<?php

namespace App\Controller;

use App\Dto\SignalementDto;
use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\MotifSignalement;
use App\Service\Moderation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Signalement d'une annonce par un utilisateur connecté ; examiné dans l'administration. */
#[Route('/api', name: 'api_signalements_', format: 'json')]
class SignalementController extends AbstractController
{
    /** Motifs proposés dans l'application : [{"valeur": "arnaque", "libelle": "..."}]. */
    #[Route('/signalements/motifs', name: 'motifs', methods: ['GET'])]
    public function motifs(): JsonResponse
    {
        return $this->json(array_map(
            fn (MotifSignalement $m) => ['valeur' => $m->value, 'libelle' => $m->libelle()],
            MotifSignalement::cases(),
        ));
    }

    /** {"motif": "arnaque", "commentaire": "..."} → 201 */
    #[Route('/annonces/{id}/signalements', name: 'signaler', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY', message: 'Connectez-vous pour signaler une annonce.')]
    public function signaler(
        Annonce $annonce,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] SignalementDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        Moderation $moderation,
    ): JsonResponse {
        $moderation->signaler($annonce, $utilisateur, $donnees);

        return $this->json(
            ['message' => 'Merci. Votre signalement a été transmis à l\'équipe LogeTogo, qui va examiner cette annonce.'],
            Response::HTTP_CREATED,
        );
    }
}
