<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Entity\Favori;
use App\Entity\Utilisateur;
use App\Repository\FavoriRepository;
use App\Security\Voter\AnnonceVoter;
use App\Service\Notation;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Favoris de l'utilisateur connecté, conservés sur le serveur (retrouvés sur tous ses appareils).
 * Ajouter et retirer sont idempotents : les répéter ne change rien.
 */
#[Route('/api/favoris', name: 'api_favoris_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class FavoriController extends AbstractController
{
    /** Annonces en favori, les plus récentes d'abord (même format que la liste des annonces). */
    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(#[CurrentUser] Utilisateur $utilisateur, FavoriRepository $favoris, Notation $notation): JsonResponse
    {
        $annonces = $favoris->trouverAnnoncesFavorites($utilisateur);
        $notation->completer($annonces);

        return $this->json(
            $annonces,
            context: ['groups' => ['annonce:liste', 'annonceur:public']],
        );
    }

    /** Ajoute l'annonce aux favoris (204, même si elle y était déjà). */
    #[Route('/{id}', name: 'ajouter', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted(AnnonceVoter::VOIR, subject: 'annonce', message: 'Annonce introuvable.', statusCode: 404)]
    public function ajouter(
        Annonce $annonce,
        #[CurrentUser] Utilisateur $utilisateur,
        FavoriRepository $favoris,
        EntityManagerInterface $em,
    ): Response {
        if (null === $favoris->trouver($utilisateur, $annonce)) {
            $utilisateur->addFavori((new Favori())->setAnnonce($annonce));
            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Double appui simultané depuis deux appareils : le favori existe déjà, c'est le résultat voulu.
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** Retire l'annonce des favoris (204, même si elle n'y était pas). */
    #[Route('/{id}', name: 'retirer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function retirer(
        Annonce $annonce,
        #[CurrentUser] Utilisateur $utilisateur,
        FavoriRepository $favoris,
        EntityManagerInterface $em,
    ): Response {
        $favori = $favoris->trouver($utilisateur, $annonce);
        if (null !== $favori) {
            $utilisateur->removeFavori($favori);
            $em->remove($favori);
            $em->flush();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
