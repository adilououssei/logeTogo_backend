<?php

namespace App\Entity;

use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Alerte affichée à un utilisateur (nouvelle annonce, message, statut, avis). */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_notification_destinataire_lue', columns: ['destinataire_id', 'est_lue'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $destinataire = null;

    #[ORM\Column(length: 30, enumType: TypeNotification::class)]
    private ?TypeNotification $type = null;

    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $contenu = null;

    /** Annonce à ouvrir quand on touche la notification. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Annonce $annonce = null;

    #[ORM\Column]
    private bool $estLue = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
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
