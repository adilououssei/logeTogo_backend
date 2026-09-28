<?php

namespace App\Entity;

use App\Enum\MotifSignalement;
use App\Repository\SignalementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement d'une annonce par un utilisateur (arnaque, bien déjà loué…),
 * examiné dans l'interface d'administration : l'annonce est suspendue ou le signalement rejeté.
 */
#[ORM\Entity(repositoryClass: SignalementRepository::class)]
#[ORM\Index(columns: ['statut'])]
class Signalement
{
    public const EN_ATTENTE = 'en_attente';
    /** L'annonce a été suspendue. */
    public const TRAITE = 'traite';
    /** Signalement jugé sans fondement : l'annonce reste en ligne. */
    public const REJETE = 'rejete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Annonce $annonce;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $auteur;

    #[ORM\Column(length: 30, enumType: MotifSignalement::class)]
    private MotifSignalement $motif;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire;

    #[ORM\Column(length: 20)]
    private string $statut = self::EN_ATTENTE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateTraitement = null;

    public function __construct(Annonce $annonce, Utilisateur $auteur, MotifSignalement $motif, ?string $commentaire)
    {
        $this->annonce = $annonce;
        $this->auteur = $auteur;
        $this->motif = $motif;
        $this->commentaire = $commentaire;
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnnonce(): Annonce
    {
        return $this->annonce;
    }

    public function getAuteur(): Utilisateur
    {
        return $this->auteur;
    }

    public function getMotif(): MotifSignalement
    {
        return $this->motif;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function estEnAttente(): bool
    {
        return self::EN_ATTENTE === $this->statut;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateTraitement(): ?\DateTimeImmutable
    {
        return $this->dateTraitement;
    }

    public function cloturer(bool $annonceSuspendue): static
    {
        $this->statut = $annonceSuspendue ? self::TRAITE : self::REJETE;
        $this->dateTraitement = new \DateTimeImmutable();

        return $this;
    }
}
