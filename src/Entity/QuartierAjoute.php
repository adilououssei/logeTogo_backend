<?php

namespace App\Entity;

use App\Enum\Region;
use App\Repository\QuartierAjouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quartier absent de la liste officielle (config/lieux/quartiers_togo.csv), ajouté
 * automatiquement à la publication d'une annonce. Il est ensuite reconnu et proposé
 * comme les autres.
 */
#[ORM\Entity(repositoryClass: QuartierAjouteRepository::class)]
#[ORM\HasLifecycleCallbacks]
class QuartierAjoute
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    /** Nom sans accents ni majuscules : un même quartier n'est ajouté qu'une fois. */
    #[ORM\Column(length: 100, unique: true)]
    private ?string $nomNormalise = null;

    #[ORM\Column(length: 100)]
    private ?string $ville = null;

    #[ORM\Column(enumType: Region::class)]
    private ?Region $region = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Utilisateur $ajoutePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateAjout = null;

    #[ORM\PrePersist]
    public function initialiserDateAjout(): void
    {
        $this->dateAjout ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getNomNormalise(): ?string
    {
        return $this->nomNormalise;
    }

    public function setNomNormalise(string $nomNormalise): static
    {
        $this->nomNormalise = $nomNormalise;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(Region $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getAjoutePar(): ?Utilisateur
    {
        return $this->ajoutePar;
    }

    public function setAjoutePar(?Utilisateur $ajoutePar): static
    {
        $this->ajoutePar = $ajoutePar;

        return $this;
    }

    public function getDateAjout(): ?\DateTimeImmutable
    {
        return $this->dateAjout;
    }
}
