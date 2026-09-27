<?php

namespace App\Entity;

use App\Repository\AvisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Note (1 à 5) et commentaire laissés sur un logement OU sur un agent.
 * Un utilisateur ne peut noter qu'une fois le même logement ou le même agent.
 */
#[ORM\Entity(repositoryClass: AvisRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_avis_auteur_annonce', columns: ['auteur_id', 'annonce_id'])]
#[ORM\UniqueConstraint(name: 'uniq_avis_auteur_agent', columns: ['auteur_id', 'agent_evalue_id'])]
class Avis
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $auteur = null;

    /** Logement évalué (ou null si l'avis porte sur un agent). */
    #[ORM\ManyToOne(inversedBy: 'avis')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Annonce $annonce = null;

    /** Agent ou propriétaire évalué (ou null si l'avis porte sur un logement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Utilisateur $agentEvalue = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'La note doit être comprise entre {{ min }} et {{ max }}.')]
    private ?int $note = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $commentaire = null;

    /** Masqué par la modération. */
    #[ORM\Column]
    private bool $estMasque = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validerCible(ExecutionContextInterface $contexte): void
    {
        if ((null === $this->annonce) === (null === $this->agentEvalue)) {
            $contexte->buildViolation('Un avis porte soit sur un logement, soit sur un agent.')->addViolation();
        }
        if (null !== $this->agentEvalue && $this->agentEvalue === $this->auteur) {
            $contexte->buildViolation('Vous ne pouvez pas vous évaluer vous-même.')->atPath('agentEvalue')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(Utilisateur $auteur): static
    {
        $this->auteur = $auteur;

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

    public function getAgentEvalue(): ?Utilisateur
    {
        return $this->agentEvalue;
    }

    public function setAgentEvalue(?Utilisateur $agentEvalue): static
    {
        $this->agentEvalue = $agentEvalue;

        return $this;
    }

    public function getNote(): ?int
    {
        return $this->note;
    }

    public function setNote(int $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function isEstMasque(): bool
    {
        return $this->estMasque;
    }

    public function setEstMasque(bool $estMasque): static
    {
        $this->estMasque = $estMasque;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
