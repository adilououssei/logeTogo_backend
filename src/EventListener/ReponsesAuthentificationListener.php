<?php

namespace App\EventListener;

use App\Entity\Utilisateur;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Harmonise les réponses de connexion et d'erreur de jeton pour l'application mobile :
 * - connexion réussie : {"token": "...", "utilisateur": {...}} (même format que l'inscription) ;
 * - erreurs : {"code": 401, "message": "..."} avec des messages en français.
 */
final class ReponsesAuthentificationListener
{
    public function __construct(private readonly NormalizerInterface $normaliseur)
    {
    }

    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
    public function connexionReussie(AuthenticationSuccessEvent $evenement): void
    {
        $utilisateur = $evenement->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $evenement->setData($evenement->getData() + [
            'utilisateur' => $this->normaliseur->normalize($utilisateur, 'json', ['groups' => ['utilisateur:lecture']]),
        ]);
    }

    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function connexionEchouee(AuthenticationFailureEvent $evenement): void
    {
        $exception = $evenement->getException();
        // Même message que l'identifiant existe ou non, pour ne pas révéler les comptes existants.
        if ($exception instanceof BadCredentialsException || $exception instanceof UserNotFoundException) {
            $evenement->setResponse(new JWTAuthenticationFailureResponse('Identifiant ou mot de passe incorrect.'));
        }
    }

    /** Traduit les réponses de déconnexion du bundle de renouvellement (qui passe avant). */
    #[AsEventListener(event: LogoutEvent::class, priority: -64, dispatcher: 'security.event_dispatcher.api')]
    public function deconnexion(LogoutEvent $evenement): void
    {
        $reponse = $evenement->getResponse();
        if (!$reponse instanceof JsonResponse) {
            return;
        }
        $message = match ($reponse->getStatusCode()) {
            200 => 'Vous êtes déconnecté.',
            400 => 'Jeton de renouvellement manquant.',
            default => null,
        };
        if (null !== $message) {
            $reponse->setData(['code' => $reponse->getStatusCode(), 'message' => $message]);
        }
    }

    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    public function jetonAbsent(JWTNotFoundEvent $evenement): void
    {
        $evenement->setResponse(new JWTAuthenticationFailureResponse('Vous devez être connecté.'));
    }

    #[AsEventListener(event: Events::JWT_INVALID)]
    public function jetonInvalide(JWTInvalidEvent $evenement): void
    {
        $evenement->setResponse(new JWTAuthenticationFailureResponse('Session invalide. Veuillez vous reconnecter.'));
    }

    #[AsEventListener(event: Events::JWT_EXPIRED)]
    public function jetonExpire(JWTExpiredEvent $evenement): void
    {
        $evenement->setResponse(new JWTAuthenticationFailureResponse('Session expirée. Veuillez vous reconnecter.'));
    }
}
