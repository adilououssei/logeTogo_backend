<?php

namespace App\Entity;

use App\Enum\StatutAnnonce;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use App\Repository\AnnonceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bien proposé à la location ou à la vente, publié par un agent ou un propriétaire.
 */
#[ORM\Entity(repositoryClass: AnnonceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_annonce_region_statut', columns: ['localisation_region', 'statut'])]
#[ORM\Index(name: 'idx_annonce_date_publication', columns: ['date_publication'])]
class Annonce
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le titre est requis.')]
    #[Assert\Length(max: 150)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'La description est requise.')]
    #[Groups(['annonce:detail'])]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: TypeBien::class)]
    #[Assert\NotNull]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?TypeBien $typeBien = null;

    #[ORM\Column(length: 20, enumType: TypeTransaction::class)]
    #[Assert\NotNull]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?TypeTransaction $typeTransaction = null;

    /** En FCFA : loyer mensuel (location) ou prix total (vente). */
    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\NotNull(message: 'Le prix est requis.')]
    #[Assert\Positive]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $prix = null;

    /** Location uniquement : nombre de mois d'avance à payer à l'entrée. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $avanceMois = null;

    /** Location uniquement : nombre de mois de caution. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $cautionMois = null;

    /** Commission de l'agent (démarcheur) en FCFA. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $commission = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\PositiveOrZero]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private int $chambres = 0;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\PositiveOrZero]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private int $sallesDeBain = 0;

    /** En m². */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?int $superficie = null;

    /** @var list<string> Ex. « Eau courante », « Électricité », « Climatisation »… */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['annonce:detail'])]
    private array $equipements = [];

    #[ORM\Column(length: 20, enumType: StatutAnnonce::class)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private StatutAnnonce $statut = StatutAnnonce::DISPONIBLE;

    #[ORM\Column]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private int $nombreVues = 0;

    /** Note moyenne des avis (calculée, non enregistrée en base). */
    private ?float $noteMoyenne = null;

    /** Nombre d'avis (calculé, non enregistré en base). */
    private int $nombreAvis = 0;

    #[ORM\Embedded(class: Localisation::class)]
    #[Assert\Valid]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private Localisation $localisation;

    #[ORM\Embedded(class: ContactAnnonce::class)]
    #[Assert\Valid]
    #[Groups(['annonce:prive'])]
    private ContactAnnonce $contact;

    #[ORM\ManyToOne(inversedBy: 'annonces')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?Utilisateur $publiePar = null;

    /** @var Collection<int, Media> */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'annonce', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordre' => \SortDirection::Ascending])]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private Collection $medias;

    /** @var Collection<int, Avis> */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'annonce')]
    private Collection $avis;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['annonce:liste', 'annonce:detail'])]
    private ?\DateTimeImmutable $datePublication = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['annonce:detail'])]
    private ?\DateTimeImmutable $dateModification = null;

    public function __construct()
    {
        $this->localisation = new Localisation();
        $this->contact = new ContactAnnonce();
        $this->medias = new ArrayCollection();
        $this->avis = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDatePublication(): void
    {
        $this->datePublication ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function actualiserDateModification(): void
    {
        $this->dateModification = new \DateTimeImmutable();
    }

    /** Somme à payer à l'entrée pour une location : avance + commission. */
    #[Groups(['annonce:liste', 'annonce:detail'])]
    public function getTotalEntree(): ?int
    {
        if (TypeTransaction::LOCATION !== $this->typeTransaction || null === $this->prix) {
            return null;
        }

        return $this->prix * ($this->avanceMois ?? 0) + ($this->commission ?? 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getTypeBien(): ?TypeBien
    {
        return $this->typeBien;
    }

    public function setTypeBien(TypeBien $typeBien): static
    {
        $this->typeBien = $typeBien;

        return $this;
    }

    public function getTypeTransaction(): ?TypeTransaction
    {
        return $this->typeTransaction;
    }

    public function setTypeTransaction(TypeTransaction $typeTransaction): static
    {
        $this->typeTransaction = $typeTransaction;

        return $this;
    }

    public function getPrix(): ?int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    public function getAvanceMois(): ?int
    {
        return $this->avanceMois;
    }

    public function setAvanceMois(?int $avanceMois): static
    {
        $this->avanceMois = $avanceMois;

        return $this;
    }

    public function getCautionMois(): ?int
    {
        return $this->cautionMois;
    }

    public function setCautionMois(?int $cautionMois): static
    {
        $this->cautionMois = $cautionMois;

        return $this;
    }

    public function getCommission(): ?int
    {
        return $this->commission;
    }

    public function setCommission(?int $commission): static
    {
        $this->commission = $commission;

        return $this;
    }

    public function getChambres(): int
    {
        return $this->chambres;
    }

    public function setChambres(int $chambres): static
    {
        $this->chambres = $chambres;

        return $this;
    }

    public function getSallesDeBain(): int
    {
        return $this->sallesDeBain;
    }

    public function setSallesDeBain(int $sallesDeBain): static
    {
        $this->sallesDeBain = $sallesDeBain;

        return $this;
    }

    public function getSuperficie(): ?int
    {
        return $this->superficie;
    }

    public function setSuperficie(?int $superficie): static
    {
        $this->superficie = $superficie;

        return $this;
    }

    /** @return list<string> */
    public function getEquipements(): array
    {
        return $this->equipements;
    }

    /** @param list<string> $equipements */
    public function setEquipements(array $equipements): static
    {
        $this->equipements = array_values(array_unique($equipements));

        return $this;
    }

    public function getStatut(): StatutAnnonce
    {
        return $this->statut;
    }

    public function setStatut(StatutAnnonce $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    #[Groups(['annonce:liste', 'annonce:detail'])]
    public function getNoteMoyenne(): ?float
    {
        return $this->noteMoyenne;
    }

    #[Groups(['annonce:liste', 'annonce:detail'])]
    public function getNombreAvis(): int
    {
        return $this->nombreAvis;
    }

    /** Renseigné par le service Notation avant l'envoi à l'application. */
    public function definirNotes(?float $noteMoyenne, int $nombreAvis): static
    {
        $this->noteMoyenne = null !== $noteMoyenne ? round($noteMoyenne, 1) : null;
        $this->nombreAvis = $nombreAvis;

        return $this;
    }

    public function getNombreVues(): int
    {
        return $this->nombreVues;
    }

    public function incrementerVues(): static
    {
        ++$this->nombreVues;

        return $this;
    }

    public function getLocalisation(): Localisation
    {
        return $this->localisation;
    }

    public function setLocalisation(Localisation $localisation): static
    {
        $this->localisation = $localisation;

        return $this;
    }

    public function getContact(): ContactAnnonce
    {
        return $this->contact;
    }

    public function setContact(ContactAnnonce $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getPubliePar(): ?Utilisateur
    {
        return $this->publiePar;
    }

    public function setPubliePar(?Utilisateur $publiePar): static
    {
        $this->publiePar = $publiePar;

        return $this;
    }

    /** @return Collection<int, Media> */
    public function getMedias(): Collection
    {
        return $this->medias;
    }

    public function addMedia(Media $media): static
    {
        if (!$this->medias->contains($media)) {
            $this->medias->add($media);
            $media->setAnnonce($this);
        }

        return $this;
    }

    public function removeMedia(Media $media): static
    {
        $this->medias->removeElement($media);

        return $this;
    }

    /** @return Collection<int, Avis> */
    public function getAvis(): Collection
    {
        return $this->avis;
    }

    public function getDatePublication(): ?\DateTimeImmutable
    {
        return $this->datePublication;
    }

    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->dateModification;
    }
}
