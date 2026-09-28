<?php

namespace App\Controller;

use App\Dto\DemandeCodeDto;
use App\Dto\InscriptionDto;
use App\Dto\ReinitialisationMotDePasseDto;
use App\Dto\VerificationCodeDto;
use App\Entity\Utilisateur;
use App\Service\InscriptionUtilisateur;
use App\Service\ReinitialisationMotDePasse;
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

/**
 * Toutes les réponses de connexion ont le même format :
 * {"token": "<jeton d'accès, 1 h>", "refresh_token": "<jeton de renouvellement>", "utilisateur": {...}}
 */
#[Route('/api/auth', name: 'api_auth_', format: 'json')]
class AuthentificationController extends AbstractController
{
    /** Crée un compte et connecte directement l'utilisateur (réponse 201). */
    #[Route('/inscription', name: 'inscription', methods: ['POST'])]
    public function inscription(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] InscriptionDto $donnees,
        InscriptionUtilisateur $inscription,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        AuthenticationSuccessHandler $connexionReussie,
    ): Response {
        // Données refusées (ex. email déjà utilisé) : 422, voir ErreursValidationListener.
        $utilisateur = $inscription->inscrire($donnees);

        // Même réponse qu'une connexion réussie (jeton d'accès, jeton de renouvellement, utilisateur).
        $reponse = $connexionReussie->handleAuthenticationSuccess($utilisateur);
        $reponse->setStatusCode(Response::HTTP_CREATED);

        return $reponse;
    }

    /**
     * Connexion : {"identifiant": "kofi.mensah", "motDePasse": "..."}.
     * Traitée entièrement par le pare-feu (json_login) ; ce code n'est jamais exécuté.
     */
    #[Route('/connexion', name: 'connexion', methods: ['POST'])]
    public function connexion(): never
    {
        throw new \LogicException('Cette route est interceptée par le pare-feu « api » (json_login).');
    }

    /**
     * Nouveau jeton d'accès sans mot de passe : {"refresh_token": "..."}.
     * Traitée par le pare-feu (refresh_jwt) ; le jeton de renouvellement envoyé est remplacé.
     */
    #[Route('/renouveler', name: 'renouveler', methods: ['POST'])]
    public function renouveler(): never
    {
        throw new \LogicException('Cette route est interceptée par le pare-feu « api » (refresh_jwt).');
    }

    /**
     * Déconnexion : {"refresh_token": "..."} — le jeton de renouvellement est supprimé,
     * l'appareil devra se reconnecter. Traitée par le pare-feu (logout).
     */
    #[Route('/deconnexion', name: 'deconnexion', methods: ['POST'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Cette route est interceptée par le pare-feu « api » (logout).');
    }

    /**
     * Mot de passe oublié, étape 1 : un code à 6 chiffres est envoyé à l'email du compte.
     * Même réponse que le compte existe ou non (pour ne pas révéler les identifiants).
     */
    #[Route('/mot-de-passe-oublie', name: 'mot_de_passe_oublie', methods: ['POST'])]
    public function demanderCode(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] DemandeCodeDto $donnees,
        Request $requete,
        ReinitialisationMotDePasse $reinitialisation,
    ): JsonResponse {
        $reinitialisation->demander($donnees->identifiant, $requete->getClientIp());

        return $this->json([
            'message' => "Si ce compte a une adresse email, un code à 6 chiffres vient d'y être envoyé. Il est valable 15 minutes.",
        ]);
    }

    /** Étape 2 : {"identifiant", "code"} → 200 si le code est bon, sinon 422 (5 essais au plus). */
    #[Route('/mot-de-passe-oublie/verifier', name: 'mot_de_passe_oublie_verifier', methods: ['POST'])]
    public function verifierCode(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] VerificationCodeDto $donnees,
        Request $requete,
        ReinitialisationMotDePasse $reinitialisation,
    ): JsonResponse {
        $reinitialisation->verifier($donnees->identifiant, $donnees->code, $requete->getClientIp());

        return $this->json(['valide' => true]);
    }

    /** Étape 3 : nouveau mot de passe ; l'utilisateur est connecté directement (même réponse qu'une connexion). */
    #[Route('/mot-de-passe-oublie/reinitialiser', name: 'mot_de_passe_oublie_reinitialiser', methods: ['POST'])]
    public function reinitialiser(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ReinitialisationMotDePasseDto $donnees,
        Request $requete,
        ReinitialisationMotDePasse $reinitialisation,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        AuthenticationSuccessHandler $connexionReussie,
    ): Response {
        $utilisateur = $reinitialisation->reinitialiser($donnees->identifiant, $donnees->code, $donnees->nouveauMotDePasse, $requete->getClientIp());

        return $connexionReussie->handleAuthenticationSuccess($utilisateur);
    }

    /** Profil de l'utilisateur connecté (en-tête Authorization: Bearer <token>). */
    #[Route('/moi', name: 'moi', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function moi(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->json($utilisateur, context: ['groups' => ['utilisateur:lecture']]);
    }
}
