<?php

namespace App\Entity;

use App\Repository\DemandeVerificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de vérification d'identité : l'utilisateur envoie la photo d'une pièce
 * d'identité, un administrateur l'accepte (badge « vérifié ») ou la refuse.
 * Les photos sont stockées hors du dossier public et supprimées dès la décision.
 */
#[ORM\Entity(repositoryClass: DemandeVerificationRepository::class)]
class DemandeVerification
{
    public const EN_ATTENTE = 'en_attente';
    public const APPROUVEE = 'approuvee';
    public const REFUSEE = 'refusee';

    public const TYPES_DOCUMENT = [
        'cni' => 'Carte nationale d\'identité',
        'passeport' => 'Passeport',
        'carte_electeur' => 'Carte d\'électeur',
        'permis' => 'Permis de conduire',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 20)]
    private string $typeDocument;

    /** Chemins relatifs au dossier privé des vérifications ; vidés après la décision. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recto = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verso = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::EN_ATTENTE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRefus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDecision = null;

    public function __construct(Utilisateur $utilisateur, string $typeDocument)
    {
        $this->utilisateur = $utilisateur;
        $this->typeDocument = $typeDocument;
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getTypeDocument(): string
    {
        return $this->typeDocument;
    }

    public function getRecto(): ?string
    {
        return $this->recto;
    }

    public function getVerso(): ?string
    {
        return $this->verso;
    }

    public function definirDocuments(string $recto, ?string $verso): static
    {
        $this->recto = $recto;
        $this->verso = $verso;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function estEnAttente(): bool
    {
        return self::EN_ATTENTE === $this->statut;
    }

    public function estApprouvee(): bool
    {
        return self::APPROUVEE === $this->statut;
    }

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function getDateDecision(): ?\DateTimeImmutable
    {
        return $this->dateDecision;
    }

    /** Décision de l'administrateur ; les chemins des photos sont oubliés (fichiers supprimés à part). */
    public function decider(bool $approuvee, ?string $motifRefus = null): static
    {
        $this->statut = $approuvee ? self::APPROUVEE : self::REFUSEE;
        $this->motifRefus = $approuvee ? null : $motifRefus;
        $this->dateDecision = new \DateTimeImmutable();
        $this->recto = null;
        $this->verso = null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function presenter(): array
    {
        return [
            'id' => $this->id,
            'typeDocument' => $this->typeDocument,
            'statut' => $this->statut,
            'motifRefus' => $this->motifRefus,
            'dateDemande' => $this->dateDemande->format(\DATE_ATOM),
            'dateDecision' => $this->dateDecision?->format(\DATE_ATOM),
        ];
    }
}
