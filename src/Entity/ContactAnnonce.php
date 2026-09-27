<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Coordonnées à utiliser pour une annonce (intégrées à la table `annonce`,
 * colonnes contact_*). Si elles sont vides, on utilise celles de l'annonceur.
 * Réservées aux utilisateurs connectés.
 */
#[ORM\Embeddable]
class ContactAnnonce
{
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    private ?string $telephone = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    private ?string $whatsapp = null;

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone ?: null;

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): static
    {
        $this->whatsapp = $whatsapp ?: null;

        return $this;
    }
}
