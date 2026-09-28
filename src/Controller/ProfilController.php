<?php

namespace App\Controller;

use App\Dto\ChangementMotDePasseDto;
use App\Dto\PreferencesDto;
use App\Dto\ProfilDto;
use App\Entity\Utilisateur;
use App\Service\GestionProfil;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Profil de l'utilisateur connecté : informations, photo et mot de passe. */
#[Route('/api/profil', name: 'api_profil_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProfilController extends AbstractController
{
    private const GROUPES = ['groups' => ['utilisateur:lecture']];

    /** Remplace prénom, nom, région, email et téléphone ; renvoie le profil à jour. */
    #[Route('', name: 'modifier', methods: ['PUT'])]
    public function modifier(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ProfilDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        GestionProfil $profil,
    ): JsonResponse {
        return $this->json($profil->modifier($utilisateur, $donnees), context: self::GROUPES);
    }

    /**
     * Change le mot de passe. Les autres appareils sont déconnectés ; celui-ci reçoit
     * de nouveaux jetons : {"token", "refresh_token", "utilisateur"}.
     */
    #[Route('/mot-de-passe', name: 'mot_de_passe', methods: ['PUT'])]
    public function changerMotDePasse(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ChangementMotDePasseDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        GestionProfil $profil,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        AuthenticationSuccessHandler $connexionReussie,
    ): Response {
        $profil->changerMotDePasse($utilisateur, $donnees->motDePasseActuel, $donnees->nouveauMotDePasse);

        return $connexionReussie->handleAuthenticationSuccess($utilisateur);
    }

    /** Préférences (ex. {"alertesEmail": false}) ; renvoie le profil à jour. */
    #[Route('/preferences', name: 'preferences', methods: ['PATCH'])]
    public function preferences(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] PreferencesDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (null !== $donnees->alertesEmail) {
            $utilisateur->setAlertesEmail($donnees->alertesEmail);
        }
        $em->flush();

        return $this->json($utilisateur, context: self::GROUPES);
    }

    /** Photo de profil : multipart/form-data, champ « fichier ». */
    #[Route('/avatar', name: 'avatar', methods: ['POST'])]
    public function changerAvatar(Request $requete, #[CurrentUser] Utilisateur $utilisateur, GestionProfil $profil): JsonResponse
    {
        return $this->json($profil->changerAvatar($utilisateur, $requete->files->get('fichier')), context: self::GROUPES);
    }

    #[Route('/avatar', name: 'avatar_retirer', methods: ['DELETE'])]
    public function retirerAvatar(#[CurrentUser] Utilisateur $utilisateur, GestionProfil $profil): JsonResponse
    {
        return $this->json($profil->retirerAvatar($utilisateur), context: self::GROUPES);
    }
}
