<?php

namespace App\Controller;

use App\Dto\AvisDto;
use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Security\Voter\AnnonceVoter;
use App\Service\Notation;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Avis sur les logements et sur les agents/propriétaires.
 * Lire les avis est public ; en laisser un demande d'avoir échangé avec l'annonceur via la messagerie.
 * Réponse commune : {moyenne, nombre, peutNoter, monAvis, avis: [{id, note, commentaire, dateCreation, auteur, deMoi}]}.
 */
#[Route('/api', name: 'api_avis_', format: 'json')]
class AvisController extends AbstractController
{
    // ─── Avis sur un logement ────────────────────────────────────────────────
    #[Route('/annonces/{id}/avis', name: 'annonce_liste', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(AnnonceVoter::VOIR, subject: 'annonce', message: 'Annonce introuvable.', statusCode: 404)]
    public function listeAnnonce(Annonce $annonce, Notation $notation, #[CurrentUser] ?Utilisateur $utilisateur = null): JsonResponse
    {
        return $this->json($notation->presenter($annonce, null, $utilisateur));
    }

    /** Crée ou remplace mon avis sur ce logement (201 à la création, 200 à la modification). */
    #[Route('/annonces/{id}/avis', name: 'annonce_noter', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[IsGranted(AnnonceVoter::VOIR, subject: 'annonce', message: 'Annonce introuvable.', statusCode: 404)]
    public function noterAnnonce(
        Annonce $annonce,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AvisDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        Notation $notation,
    ): JsonResponse {
        [, $cree] = $notation->enregistrer($utilisateur, $annonce, null, $donnees);

        return $this->json($notation->presenter($annonce, null, $utilisateur), $cree ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/annonces/{id}/avis', name: 'annonce_retirer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function retirerAvisAnnonce(Annonce $annonce, #[CurrentUser] Utilisateur $utilisateur, Notation $notation): Response
    {
        $notation->supprimer($utilisateur, $annonce, null);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    // ─── Avis sur un agent ou un propriétaire ────────────────────────────────
    #[Route('/agents/{id}/avis', name: 'agent_liste', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listeAgent(#[MapEntity(id: 'id')] Utilisateur $agent, Notation $notation, #[CurrentUser] ?Utilisateur $utilisateur = null): JsonResponse
    {
        $this->verifierAnnonceur($agent);

        return $this->json($notation->presenter(null, $agent, $utilisateur));
    }

    #[Route('/agents/{id}/avis', name: 'agent_noter', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function noterAgent(
        #[MapEntity(id: 'id')] Utilisateur $agent,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AvisDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        Notation $notation,
    ): JsonResponse {
        $this->verifierAnnonceur($agent);
        [, $cree] = $notation->enregistrer($utilisateur, null, $agent, $donnees);

        return $this->json($notation->presenter(null, $agent, $utilisateur), $cree ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/agents/{id}/avis', name: 'agent_retirer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function retirerAvisAgent(#[MapEntity(id: 'id')] Utilisateur $agent, #[CurrentUser] Utilisateur $utilisateur, Notation $notation): Response
    {
        $notation->supprimer($utilisateur, null, $agent);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** Seuls les agents et propriétaires (ceux qui publient) peuvent être notés. */
    private function verifierAnnonceur(Utilisateur $agent): void
    {
        if (!$agent->getRole()->peutPublier() || !$agent->isEstActif()) {
            throw new NotFoundHttpException('Agent introuvable.');
        }
    }
}
