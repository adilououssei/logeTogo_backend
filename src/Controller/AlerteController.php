<?php

namespace App\Controller;

use App\Dto\AlerteDto;
use App\Entity\AlerteRecherche;
use App\Entity\Utilisateur;
use App\Repository\AlerteRechercheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Alertes de recherche : être prévenu des nouvelles annonces qui correspondent à ses critères. */
#[Route('/api/alertes', name: 'api_alertes_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class AlerteController extends AbstractController
{
    public const MAX_ALERTES = 10;

    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(#[CurrentUser] Utilisateur $utilisateur, AlerteRechercheRepository $alertes): JsonResponse
    {
        return $this->json($alertes->trouverPourUtilisateur($utilisateur), context: ['groups' => ['alerte:lecture']]);
    }

    /** Crée une alerte (201) ; 10 au plus par utilisateur. */
    #[Route('', name: 'creer', methods: ['POST'])]
    public function creer(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AlerteDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        AlerteRechercheRepository $alertes,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (\count($alertes->trouverPourUtilisateur($utilisateur)) >= self::MAX_ALERTES) {
            throw new UnprocessableEntityHttpException(\sprintf('Vous avez déjà %d alertes : supprimez-en une pour en créer une nouvelle.', self::MAX_ALERTES));
        }

        $alerte = (new AlerteRecherche())
            ->setUtilisateur($utilisateur)
            ->setRegion($donnees->region)
            ->setQuartier(null !== $donnees->quartier ? trim($donnees->quartier) : null)
            ->setTypeBien($donnees->typeBien)
            ->setTypeTransaction($donnees->typeTransaction)
            ->setPrixMax($donnees->prixMax);
        $em->persist($alerte);
        $em->flush();

        return $this->json($alerte, Response::HTTP_CREATED, context: ['groups' => ['alerte:lecture']]);
    }

    #[Route('/{id}', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function supprimer(AlerteRecherche $alerte, #[CurrentUser] Utilisateur $utilisateur, EntityManagerInterface $em): Response
    {
        if ($alerte->getUtilisateur() !== $utilisateur) {
            throw new NotFoundHttpException('Alerte introuvable.');
        }
        $em->remove($alerte);
        $em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
