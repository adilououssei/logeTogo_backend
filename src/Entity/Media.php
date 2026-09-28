<?php

namespace App\Entity;

use App\Enum\TypeMedia;
use App\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Photo ou vidéo d'une annonce. */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?string $url = null;

    #[ORM\Column(length: 10, enumType: TypeMedia::class)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private TypeMedia $type = TypeMedia::IMAGE;

    /** Position dans la galerie (0 = image principale). */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private int $ordre = 0;

    #[ORM\ManyToOne(inversedBy: 'medias')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Annonce $annonce = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getType(): TypeMedia
    {
        return $this->type;
    }

    public function setType(TypeMedia $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

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
}
