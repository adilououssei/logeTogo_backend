<?php

namespace App\Entity;

use App\Enum\Region;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Emplacement d'une annonce, intégré à la table `annonce` (colonnes localisation_*).
 *
 * Les coordonnées GPS exactes ne doivent être renvoyées par l'API qu'aux
 * utilisateurs connectés ; les visiteurs ne voient que la ville et le quartier.
 */
#[ORM\Embeddable]
class Localisation
{
    #[ORM\Column(length: 20, enumType: Region::class)]
    #[Assert\NotNull(message: 'La région est requise.')]
    private ?Region $region = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est requise.')]
    private ?string $ville = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le quartier est requis.')]
    private ?string $quartier = null;

    /** Précisions libres : rue, point de repère… */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    private ?float $longitude = null;

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(Region $region): static
    {
        $this->region = $region;

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

    public function getQuartier(): ?string
    {
        return $this->quartier;
    }

    public function setQuartier(string $quartier): static
    {
        $this->quartier = $quartier;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }
}
