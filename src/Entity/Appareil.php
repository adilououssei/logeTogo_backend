<?php

namespace App\Entity;

use App\Repository\AppareilRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Téléphone d'un utilisateur, enregistré pour recevoir les notifications push (service Expo).
 * Un même téléphone ne correspond qu'à un compte à la fois : le dernier connecté.
 */
#[ORM\Entity(repositoryClass: AppareilRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Appareil
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    /** Jeton fourni par Expo, ex. « ExponentPushToken[xxxxxxxx] ». */
    #[ORM\Column(length: 255, unique: true)]
    private ?string $jetonPush = null;

    /** android ou ios. */
    #[ORM\Column(length: 10)]
    private string $plateforme = 'android';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEnregistrement = null;

    #[ORM\PrePersist]
    public function initialiserDateEnregistrement(): void
    {
        $this->dateEnregistrement ??= new \DateTimeImmutable();
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

    public function getJetonPush(): ?string
    {
        return $this->jetonPush;
    }

    public function setJetonPush(string $jetonPush): static
    {
        $this->jetonPush = $jetonPush;

        return $this;
    }

    public function getPlateforme(): string
    {
        return $this->plateforme;
    }

    public function setPlateforme(string $plateforme): static
    {
        $this->plateforme = $plateforme;

        return $this;
    }

    public function getDateEnregistrement(): ?\DateTimeImmutable
    {
        return $this->dateEnregistrement;
    }

    /** Réenregistrement du même téléphone : la date repart. */
    public function actualiser(): static
    {
        $this->dateEnregistrement = new \DateTimeImmutable();

        return $this;
    }
}
