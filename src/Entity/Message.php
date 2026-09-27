<?php

namespace App\Entity;

use App\Enum\TypeMessage;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Message texte, vocal ou fichier (photo, vidéo, document) d'une conversation. */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_message_conversation_date', columns: ['conversation_id', 'date_envoi'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $expediteur = null;

    #[ORM\Column(length: 10, enumType: TypeMessage::class)]
    private TypeMessage $type = TypeMessage::TEXTE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $contenu = null;

    /** Fichier joint (vocal, photo, vidéo, document). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $urlFichier = null;

    /** Durée d'un message vocal, en secondes. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive]
    private ?int $dureeVocal = null;

    #[ORM\Column]
    private bool $estLu = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEnvoi = null;

    #[ORM\PrePersist]
    public function initialiserDateEnvoi(): void
    {
        $this->dateEnvoi ??= new \DateTimeImmutable();
    }

    /** Un message texte doit avoir un contenu ; les autres types, un fichier. */
    #[Assert\Callback]
    public function validerContenu(ExecutionContextInterface $contexte): void
    {
        if (TypeMessage::TEXTE === $this->type && '' === trim((string) $this->contenu)) {
            $contexte->buildViolation('Le message est vide.')->atPath('contenu')->addViolation();
        }
        if (TypeMessage::TEXTE !== $this->type && !$this->urlFichier) {
            $contexte->buildViolation('Le fichier est requis.')->atPath('urlFichier')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getExpediteur(): ?Utilisateur
    {
        return $this->expediteur;
    }

    public function setExpediteur(Utilisateur $expediteur): static
    {
        $this->expediteur = $expediteur;

        return $this;
    }

    public function getType(): TypeMessage
    {
        return $this->type;
    }

    public function setType(TypeMessage $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(?string $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getUrlFichier(): ?string
    {
        return $this->urlFichier;
    }

    public function setUrlFichier(?string $urlFichier): static
    {
        $this->urlFichier = $urlFichier;

        return $this;
    }

    public function getDureeVocal(): ?int
    {
        return $this->dureeVocal;
    }

    public function setDureeVocal(?int $dureeVocal): static
    {
        $this->dureeVocal = $dureeVocal;

        return $this;
    }

    public function isEstLu(): bool
    {
        return $this->estLu;
    }

    public function setEstLu(bool $estLu): static
    {
        $this->estLu = $estLu;

        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }
}
