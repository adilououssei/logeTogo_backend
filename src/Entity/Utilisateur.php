<?php

namespace App\Entity;

use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte de l'application : locataire, propriétaire, agent ou administrateur.
 *
 * La connexion se fait par identifiant (« prenom.nom », généré à l'inscription)
 * et mot de passe ; l'email et le téléphone sont optionnels.
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['identifiant'], message: 'Cet identifiant est déjà utilisé.')]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.', ignoreNull: true)]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(\.[a-z0-9]+)*$/', message: 'Identifiant invalide.')]
    #[Groups(['utilisateur:lecture'])]
    private ?string $identifiant = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est requis.')]
    #[Assert\Length(max: 100)]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est requis.')]
    #[Assert\Length(max: 100)]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    #[Assert\Email(message: 'Email invalide.')]
    #[Groups(['utilisateur:lecture', 'annonceur:prive'])]
    private ?string $email = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['utilisateur:lecture', 'annonceur:prive'])]
    private ?string $telephone = null;

    /** Numéro WhatsApp (souvent le même que le téléphone) : bouton « WhatsApp » des annonces. */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(min: 8, max: 30)]
    #[Groups(['utilisateur:lecture', 'annonceur:prive'])]
    private ?string $whatsapp = null;

    /** Indicatif du téléphone et du WhatsApp (ex. +228). */
    #[ORM\Column(length: 6, nullable: true)]
    #[Groups(['utilisateur:lecture', 'annonceur:prive'])]
    private ?string $indicatifPays = null;

    #[ORM\Column(length: 20, enumType: Region::class)]
    #[Assert\NotNull(message: 'La région est requise.')]
    #[Groups(['utilisateur:lecture'])]
    private ?Region $region = null;

    #[ORM\Column(length: 20, enumType: RoleUtilisateur::class)]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private RoleUtilisateur $role = RoleUtilisateur::LOCATAIRE;

    /** Mot de passe haché (jamais en clair). */
    #[ORM\Column]
    private ?string $motDePasse = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private ?string $avatar = null;

    /** Identité vérifiée (badge de confiance pour les agents). */
    #[ORM\Column]
    #[Groups(['utilisateur:lecture', 'annonceur:public'])]
    private bool $verifie = false;

    /** Faux lorsqu'un administrateur suspend le compte. */
    #[ORM\Column]
    private bool $estActif = true;

    /** Recevoir aussi par email les nouvelles annonces de ses alertes de recherche. */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['utilisateur:lecture'])]
    private bool $alertesEmail = true;

    /** Note moyenne des avis (calculée, non enregistrée en base). */
    private ?float $noteMoyenne = null;

    /** Nombre d'avis (calculé, non enregistré en base). */
    private int $nombreAvis = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['utilisateur:lecture'])]
    private ?\DateTimeImmutable $dateInscription = null;

    /** @var Collection<int, Annonce> */
    #[ORM\OneToMany(targetEntity: Annonce::class, mappedBy: 'publiePar')]
    private Collection $annonces;

    /** @var Collection<int, Favori> */
    #[ORM\OneToMany(targetEntity: Favori::class, mappedBy: 'utilisateur', cascade: ['persist'], orphanRemoval: true)]
    private Collection $favoris;

    public function __construct()
    {
        $this->annonces = new ArrayCollection();
        $this->favoris = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDateInscription(): void
    {
        $this->dateInscription ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifiant(): ?string
    {
        return $this->identifiant;
    }

    public function setIdentifiant(string $identifiant): static
    {
        $this->identifiant = $identifiant;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->identifiant;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER', $this->role->roleSymfony()];
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email ?: null;

        return $this;
    }

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

    public function getIndicatifPays(): ?string
    {
        return $this->indicatifPays;
    }

    public function setIndicatifPays(?string $indicatifPays): static
    {
        $this->indicatifPays = $indicatifPays;

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

    public function getRole(): RoleUtilisateur
    {
        return $this->role;
    }

    public function setRole(RoleUtilisateur $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->motDePasse;
    }

    public function getMotDePasse(): ?string
    {
        return $this->motDePasse;
    }

    /** Attend un mot de passe DÉJÀ haché (UserPasswordHasherInterface). */
    public function setMotDePasse(string $motDePasseHache): static
    {
        $this->motDePasse = $motDePasseHache;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function isAlertesEmail(): bool
    {
        return $this->alertesEmail;
    }

    public function setAlertesEmail(bool $alertesEmail): static
    {
        $this->alertesEmail = $alertesEmail;

        return $this;
    }

    public function isVerifie(): bool
    {
        return $this->verifie;
    }

    public function setVerifie(bool $verifie): static
    {
        $this->verifie = $verifie;

        return $this;
    }

    #[Groups(['annonceur:public', 'utilisateur:lecture'])]
    public function getNoteMoyenne(): ?float
    {
        return $this->noteMoyenne;
    }

    #[Groups(['annonceur:public', 'utilisateur:lecture'])]
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

    public function isEstActif(): bool
    {
        return $this->estActif;
    }

    public function setEstActif(bool $estActif): static
    {
        $this->estActif = $estActif;

        return $this;
    }

    public function getDateInscription(): ?\DateTimeImmutable
    {
        return $this->dateInscription;
    }

    /** @return Collection<int, Annonce> */
    public function getAnnonces(): Collection
    {
        return $this->annonces;
    }

    public function addAnnonce(Annonce $annonce): static
    {
        if (!$this->annonces->contains($annonce)) {
            $this->annonces->add($annonce);
            $annonce->setPubliePar($this);
        }

        return $this;
    }

    /** @return Collection<int, Favori> */
    public function getFavoris(): Collection
    {
        return $this->favoris;
    }

    public function addFavori(Favori $favori): static
    {
        if (!$this->favoris->contains($favori)) {
            $this->favoris->add($favori);
            $favori->setUtilisateur($this);
        }

        return $this;
    }

    public function removeFavori(Favori $favori): static
    {
        $this->favoris->removeElement($favori);

        return $this;
    }
}
