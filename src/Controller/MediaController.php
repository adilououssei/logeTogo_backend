<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Entity\Media;
use App\Security\Voter\AnnonceVoter;
use App\Service\StockageMedias;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Photos et vidéos d'une annonce (auteur de l'annonce ou administrateur).
 * Au plus 8 photos (10 Mo chacune) et 3 vidéos (100 Mo chacune) par annonce.
 */
#[Route('/api/annonces/{id}/medias', name: 'api_medias_', requirements: ['id' => '\d+'], format: 'json')]
class MediaController extends AbstractController
{
    /**
     * Envoi en multipart/form-data, champ « fichiers[] » (un ou plusieurs fichiers).
     * Réponse 201 : liste des médias ajoutés [{"id", "url", "type", "ordre"}].
     */
    #[Route('', name: 'ajouter', methods: ['POST'])]
    #[IsGranted(AnnonceVoter::MODIFIER, subject: 'annonce', message: 'Vous ne pouvez modifier que vos propres annonces.')]
    public function ajouter(Annonce $annonce, Request $requete, StockageMedias $stockage): JsonResponse
    {
        $fichiers = array_values(array_filter(
            $requete->files->all('fichiers'),
            fn ($fichier) => $fichier instanceof UploadedFile,
        ));

        return $this->json($stockage->ajouter($annonce, $fichiers), Response::HTTP_CREATED, context: ['groups' => ['annonce:detail']]);
    }

    #[Route('/{mediaId}', name: 'supprimer', requirements: ['mediaId' => '\d+'], methods: ['DELETE'])]
    #[IsGranted(AnnonceVoter::MODIFIER, subject: 'annonce', message: 'Vous ne pouvez modifier que vos propres annonces.')]
    public function supprimer(
        Annonce $annonce,
        #[MapEntity(id: 'mediaId')] Media $media,
        StockageMedias $stockage,
    ): Response {
        if ($media->getAnnonce() !== $annonce) {
            throw new NotFoundHttpException('Ce média n\'appartient pas à cette annonce.');
        }
        $stockage->supprimer($media);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
