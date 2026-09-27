<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Échange entre la personne intéressée (demandeur) et l'agent ou le
 * propriétaire (annonceur), généralement à propos d'une annonce.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_conversation_annonce_participants', columns: ['annonce_id', 'demandeur_id', 'annonceur_id'])]
#[ORM\Index(name: 'idx_conversation_dernier_message', columns: ['date_dernier_message'])]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Annonce concernée ; conservée à null si l'annonce est supprimée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Annonce $annonce = null;

    /** Personne qui a pris contact (souvent un locataire). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $demandeur = null;

    /** Agent ou propriétaire contacté. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $annonceur = null;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['dateEnvoi' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    /** Sert à trier la liste des conversations. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDernierMessage = null;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    public function estParticipant(Utilisateur $utilisateur): bool
    {
        return $this->demandeur === $utilisateur || $this->annonceur === $utilisateur;
    }

    /** L'interlocuteur de $utilisateur dans cette conversation. */
    public function getAutreParticipant(Utilisateur $utilisateur): ?Utilisateur
    {
        return $this->demandeur === $utilisateur ? $this->annonceur : $this->demandeur;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDemandeur(): ?Utilisateur
    {
        return $this->demandeur;
    }

    public function setDemandeur(Utilisateur $demandeur): static
    {
        $this->demandeur = $demandeur;

        return $this;
    }

    public function getAnnonceur(): ?Utilisateur
    {
        return $this->annonceur;
    }

    public function setAnnonceur(Utilisateur $annonceur): static
    {
        $this->annonceur = $annonceur;

        return $this;
    }

    /** @return Collection<int, Message> */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setConversation($this);
            $this->dateDernierMessage = $message->getDateEnvoi() ?? new \DateTimeImmutable();
        }

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateDernierMessage(): ?\DateTimeImmutable
    {
        return $this->dateDernierMessage;
    }
}
