<?php

namespace App\Entity;

use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/** Alerte affichée à un utilisateur (nouvelle annonce, message, statut, avis). */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_notification_destinataire_lue', columns: ['destinataire_id', 'est_lue'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['notification:lecture'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $destinataire = null;

    #[ORM\Column(length: 30, enumType: TypeNotification::class)]
    #[Groups(['notification:lecture'])]
    private ?TypeNotification $type = null;

    #[ORM\Column(length: 150)]
    #[Groups(['notification:lecture'])]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['notification:lecture'])]
    private ?string $contenu = null;

    /** Annonce à ouvrir quand on touche la notification. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Annonce $annonce = null;

    /** Conversation à ouvrir (notification de nouveau message). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    #[ORM\Column]
    #[Groups(['notification:lecture'])]
    private bool $estLue = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['notification:lecture'])]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Groups(['notification:lecture'])]
    public function getIdAnnonce(): ?int
    {
        return $this->annonce?->getId();
    }

    #[Groups(['notification:lecture'])]
    public function getIdConversation(): ?int
    {
        return $this->conversation?->getId();
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

    /** Notification regroupée (plusieurs messages) : elle remonte en tête de liste. */
    public function rafraichir(string $contenu): static
    {
        $this->contenu = $contenu;
        $this->dateCreation = new \DateTimeImmutable();

        return $this;
    }

    public function getDestinataire(): ?Utilisateur
    {
        return $this->destinataire;
    }

    public function setDestinataire(Utilisateur $destinataire): static
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getType(): ?TypeNotification
    {
        return $this->type;
    }

    public function setType(TypeNotification $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getContenu(): ?string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;

        return $this;
    }

    public function getAnnonce(): ?Annonce
    {
        return $this->annonce;
    }

    public function setAnnonce(?Annonce $annonce): static
    {
        $this->annonce = $annonce;

        return $this;
    }

    public function isEstLue(): bool
    {
        return $this->estLue;
    }

    public function setEstLue(bool $estLue): static
    {
        $this->estLue = $estLue;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
