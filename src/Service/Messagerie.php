<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeMessage;
use App\Repository\ConversationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Conversations entre une personne intéressée et l'annonceur, et leurs messages. */
class Messagerie
{
    private const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly ConversationRepository $conversations,
        private readonly ValidatorInterface $validateur,
        private readonly Notificateur $notificateur,
        #[Autowire('%app.dossier_public%')]
        private readonly string $dossierPublic,
    ) {
    }

    /**
     * Ouvre (ou retrouve) la conversation entre $demandeur et l'auteur de l'annonce.
     *
     * @return array{0: Conversation, 1: bool} la conversation et vrai si elle vient d'être créée
     */
    public function demarrer(Annonce $annonce, Utilisateur $demandeur): array
    {
        if ($annonce->getPubliePar() === $demandeur) {
            throw $this->erreur('annonceId', 'Vous ne pouvez pas vous écrire à propos de votre propre annonce.');
        }

        $existante = $this->conversations->trouverExistante($annonce, $demandeur);
        if (null !== $existante) {
            return [$existante, false];
        }
        if (StatutAnnonce::DISPONIBLE !== $annonce->getStatut()) {
            throw $this->erreur('annonceId', 'Ce bien n\'est plus disponible.');
        }

        $conversation = (new Conversation())
            ->setAnnonce($annonce)
            ->setDemandeur($demandeur)
            ->setAnnonceur($annonce->getPubliePar());
        try {
            $this->em->persist($conversation);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux appuis simultanés : l'autre requête a créé la conversation, on la reprend.
            $this->doctrine->resetManager();

            return [$this->conversations->trouverExistante($annonce, $demandeur), false];
        }

        return [$conversation, true];
    }

    public function envoyerTexte(Conversation $conversation, Utilisateur $expediteur, string $contenu): Message
    {
        $message = (new Message())
            ->setExpediteur($expediteur)
            ->setType(TypeMessage::TEXTE)
            ->setContenu(trim($contenu));

        return $this->enregistrer($conversation, $message);
    }

    /** Photo envoyée dans la conversation (10 Mo au plus), avec une légende facultative. */
    public function envoyerImage(Conversation $conversation, Utilisateur $expediteur, ?UploadedFile $fichier, ?string $legende = null): Message
    {
        if (null === $fichier) {
            throw $this->erreur('fichier', 'Aucune photo reçue.');
        }
        if (!\in_array($fichier->getMimeType(), self::TYPES_IMAGE, true)) {
            throw $this->erreur('fichier', 'Format non accepté : envoyez une photo (JPEG, PNG, WebP, HEIC).');
        }
        foreach ($this->validateur->validate($fichier, new Assert\File(maxSize: '10M', maxSizeMessage: 'La photo dépasse 10 Mo.')) as $v) {
            throw $this->erreur('fichier', (string) $v->getMessage());
        }

        $nom = bin2hex(random_bytes(12)).'.'.($fichier->guessExtension() ?? 'jpg');
        $fichier->move(\sprintf('%s/uploads/messages/%d', $this->dossierPublic, $conversation->getId()), $nom);

        $message = (new Message())
            ->setExpediteur($expediteur)
            ->setType(TypeMessage::IMAGE)
            ->setUrlFichier(\sprintf('/uploads/messages/%d/%s', $conversation->getId(), $nom))
            ->setContenu(null !== $legende && '' !== trim($legende) ? trim($legende) : null);

        return $this->enregistrer($conversation, $message);
    }

    private function enregistrer(Conversation $conversation, Message $message): Message
    {
        $conversation->addMessage($message);
        $violations = $this->validateur->validate($message);
        if (\count($violations) > 0) {
            $conversation->getMessages()->removeElement($message);
            throw new ValidationFailedException($message, $violations);
        }
        $this->em->persist($message);
        $this->em->flush();

        // Le destinataire est prévenu (notification dans l'application + push sur son téléphone).
        $this->notificateur->nouveauMessage($message);

        return $message;
    }

    private function erreur(string $champ, string $texte): ValidationFailedException
    {
        return new ValidationFailedException(null, new ConstraintViolationList([
            new ConstraintViolation($texte, $texte, [], null, $champ, null),
        ]));
    }
}
