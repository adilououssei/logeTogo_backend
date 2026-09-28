<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\VerificationIdentite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Vérification d'identité de l'utilisateur connecté (badge « vérifié »). */
#[Route('/api/verification', name: 'api_verification_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class VerificationController extends AbstractController
{
    /** {"verifie": bool, "demande": {statut, typeDocument, motifRefus, ...} | null, "typesDocument": {...}} */
    #[Route('', name: 'etat', methods: ['GET'])]
    public function etat(#[CurrentUser] Utilisateur $utilisateur, VerificationIdentite $verification): JsonResponse
    {
        return $this->json($verification->etat($utilisateur));
    }

    /** Multipart : typeDocument, recto (photo), verso (photo, facultatif) → 201 avec le nouvel état. */
    #[Route('', name: 'demander', methods: ['POST'])]
    public function demander(Request $requete, #[CurrentUser] Utilisateur $utilisateur, VerificationIdentite $verification): JsonResponse
    {
        $verification->demander(
            $utilisateur,
            (string) $requete->request->get('typeDocument', ''),
            $requete->files->get('recto'),
            $requete->files->get('verso'),
        );

        return $this->json($verification->etat($utilisateur), Response::HTTP_CREATED);
    }
}
