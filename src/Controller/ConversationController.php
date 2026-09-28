<?php

namespace App\Controller;

use App\Dto\NouveauMessageDto;
use App\Dto\NouvelleConversationDto;
use App\Entity\Annonce;
use App\Entity\Conversation;
use App\Entity\Utilisateur;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use App\Security\Voter\AnnonceVoter;
use App\Security\Voter\ConversationVoter;
use App\Service\Messagerie;
use App\Service\PresentateurConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Messagerie entre la personne intéressée par une annonce et son auteur (agent ou propriétaire).
 * L'application interroge régulièrement /messages?apres={dernier id} pour afficher les nouveaux messages.
 */
#[Route('/api/conversations', name: 'api_conversations_', format: 'json')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ConversationController extends AbstractController
{
    /** Mes conversations, la plus récemment active d'abord, avec le nombre de messages non lus. */
    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(
        #[CurrentUser] Utilisateur $utilisateur,
        ConversationRepository $conversations,
        MessageRepository $messages,
        PresentateurConversation $presentateur,
    ): JsonResponse {
        $liste = $conversations->trouverPourUtilisateur($utilisateur);
        $derniers = $messages->trouverDerniers(array_map(fn (Conversation $c) => $c->getId(), $liste));
        $nonLus = $messages->compterNonLusParConversation($utilisateur);

        return $this->json(array_map(
            fn (Conversation $c) => $presentateur->presenter($c, $utilisateur, $derniers[$c->getId()] ?? null, $nonLus[$c->getId()] ?? 0),
            $liste,
        ));
    }

    /** Nombre total de messages non lus (pastille de l'onglet Messages) : {"total": 3}. */
    #[Route('/non-lus', name: 'non_lus', methods: ['GET'])]
    public function nonLus(#[CurrentUser] Utilisateur $utilisateur, MessageRepository $messages): JsonResponse
    {
        return $this->json(['total' => array_sum($messages->compterNonLusParConversation($utilisateur))]);
    }

    /**
     * Contacter l'auteur d'une annonce : {"annonceId": 12}.
     * Reprend la conversation existante (200) ou en crée une (201).
     */
    #[Route('', name: 'demarrer', methods: ['POST'])]
    public function demarrer(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] NouvelleConversationDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        EntityManagerInterface $em,
        Messagerie $messagerie,
        MessageRepository $messages,
        PresentateurConversation $presentateur,
    ): JsonResponse {
        $annonce = $em->find(Annonce::class, $donnees->annonceId);
        if (null === $annonce || !$this->isGranted(AnnonceVoter::VOIR, $annonce)) {
            throw new NotFoundHttpException('Annonce introuvable.');
        }

        [$conversation, $creee] = $messagerie->demarrer($annonce, $utilisateur);
        $derniers = $messages->trouverDerniers([$conversation->getId()]);
        $nonLus = $messages->compterNonLusParConversation($utilisateur);

        return $this->json(
            $presentateur->presenter($conversation, $utilisateur, $derniers[$conversation->getId()] ?? null, $nonLus[$conversation->getId()] ?? 0),
            $creee ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** Une conversation (en-tête du chat). */
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ConversationVoter::PARTICIPER, subject: 'conversation', message: 'Conversation introuvable.', statusCode: 404)]
    public function detail(
        Conversation $conversation,
        #[CurrentUser] Utilisateur $utilisateur,
        MessageRepository $messages,
        PresentateurConversation $presentateur,
    ): JsonResponse {
        $derniers = $messages->trouverDerniers([$conversation->getId()]);
        $nonLus = $messages->compterNonLusParConversation($utilisateur);

        return $this->json($presentateur->presenter($conversation, $utilisateur, $derniers[$conversation->getId()] ?? null, $nonLus[$conversation->getId()] ?? 0));
    }

    /**
     * Messages de la conversation (les 200 derniers), ou seulement ceux arrivés après ?apres={id}.
     * Les messages reçus sont marqués comme lus.
     */
    #[Route('/{id}/messages', name: 'messages', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(ConversationVoter::PARTICIPER, subject: 'conversation', message: 'Conversation introuvable.', statusCode: 404)]
    public function messages(
        Conversation $conversation,
        #[CurrentUser] Utilisateur $utilisateur,
        MessageRepository $messages,
        NotificationRepository $notifications,
        #[MapQueryParameter] ?int $apres = null,
    ): JsonResponse {
        $liste = $messages->trouverDansConversation($conversation, $apres);
        $messages->marquerLus($conversation, $utilisateur);
        $notifications->marquerLuesPourConversation($utilisateur, $conversation);

        return $this->json($liste, context: ['groups' => ['message:lecture']]);
    }

    /** Message texte : {"contenu": "..."} (201). */
    #[Route('/{id}/messages', name: 'envoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ConversationVoter::PARTICIPER, subject: 'conversation', message: 'Conversation introuvable.', statusCode: 404)]
    public function envoyer(
        Conversation $conversation,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] NouveauMessageDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        Messagerie $messagerie,
    ): JsonResponse {
        $message = $messagerie->envoyerTexte($conversation, $utilisateur, $donnees->contenu);

        return $this->json($message, Response::HTTP_CREATED, context: ['groups' => ['message:lecture']]);
    }

    /** Photo : multipart/form-data, champ « fichier » (+ « legende » facultative) (201). */
    #[Route('/{id}/messages/image', name: 'envoyer_image', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(ConversationVoter::PARTICIPER, subject: 'conversation', message: 'Conversation introuvable.', statusCode: 404)]
    public function envoyerImage(
        Conversation $conversation,
        Request $requete,
        #[CurrentUser] Utilisateur $utilisateur,
        Messagerie $messagerie,
    ): JsonResponse {
        $message = $messagerie->envoyerImage(
            $conversation,
            $utilisateur,
            $requete->files->get('fichier'),
            $requete->request->getString('legende') ?: null,
        );

        return $this->json($message, Response::HTTP_CREATED, context: ['groups' => ['message:lecture']]);
    }
}
