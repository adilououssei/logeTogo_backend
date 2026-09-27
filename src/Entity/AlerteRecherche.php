<?php

namespace App\Entity;

use App\Enum\Region;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use App\Repository\AlerteRechercheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Critères enregistrés par un utilisateur : quand une nouvelle annonce y
 * correspond, il reçoit une notification. Un critère null = « peu importe ».
 */
#[ORM\Entity(repositoryClass: AlerteRechercheRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_alerte_active_region', columns: ['est_active', 'region'])]
class AlerteRecherche
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 20, nullable: true, enumType: Region::class)]
    private ?Region $region = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $quartier = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeBien::class)]
    private ?TypeBien $typeBien = null;

    #[ORM\Column(length: 20, nullable: true, enumType: TypeTransaction::class)]
    private ?TypeTransaction $typeTransaction = null;

    /** En FCFA. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Assert\Positive]
    private ?int $prixMax = null;

    #[ORM\Column]
    private bool $estActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    /** Vrai si l'annonce respecte tous les critères renseignés. */
    public function correspondA(Annonce $annonce): bool
    {
        return (null === $this->region || $this->region === $annonce->getLocalisation()->getRegion())
            && (null === $this->quartier || 0 === strcasecmp($this->quartier, (string) $annonce->getLocalisation()->getQuartier()))
            && (null === $this->typeBien || $this->typeBien === $annonce->getTypeBien())
            && (null === $this->typeTransaction || $this->typeTransaction === $annonce->getTypeTransaction())
            && (null === $this->prixMax || $annonce->getPrix() <= $this->prixMax);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(?string $quartier): static
    {
        $this->quartier = $quartier ?: null;

        return $this;
    }

    public function getTypeBien(): ?TypeBien
    {
        return $this->typeBien;
    }

    public function setTypeBien(?TypeBien $typeBien): static
    {
        $this->typeBien = $typeBien;

        return $this;
    }

    public function getTypeTransaction(): ?TypeTransaction
    {
        return $this->typeTransaction;
    }

    public function setTypeTransaction(?TypeTransaction $typeTransaction): static
    {
        $this->typeTransaction = $typeTransaction;

        return $this;
    }

    public function getPrixMax(): ?int
    {
        return $this->prixMax;
    }

    public function setPrixMax(?int $prixMax): static
    {
        $this->prixMax = $prixMax;

        return $this;
    }

    public function isEstActive(): bool
    {
        return $this->estActive;
    }

    public function setEstActive(bool $estActive): static
    {
        $this->estActive = $estActive;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
