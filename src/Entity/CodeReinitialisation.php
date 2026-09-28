<?php

namespace App\Entity;

use App\Repository\CodeReinitialisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code à 6 chiffres envoyé par email pour réinitialiser un mot de passe oublié.
 * Seul son empreinte est enregistrée ; il expire après 15 minutes ou 5 essais manqués.
 * Un utilisateur n'a qu'un code valide à la fois : une nouvelle demande remplace l'ancien.
 */
#[ORM\Entity(repositoryClass: CodeReinitialisationRepository::class)]
class CodeReinitialisation
{
    public const DUREE_VALIDITE = '+15 minutes';
    public const ESSAIS_MAX = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(length: 255)]
    private string $empreinteCode = '';

    #[ORM\Column]
    private int $essaisManques = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dateExpiration;

    public function __construct(Utilisateur $utilisateur, string $code)
    {
        $this->utilisateur = $utilisateur;
        $this->empreinteCode = password_hash($code, \PASSWORD_DEFAULT);
        $this->dateCreation = new \DateTimeImmutable();
        $this->dateExpiration = $this->dateCreation->modify(self::DUREE_VALIDITE);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function correspond(string $code): bool
    {
        return password_verify($code, $this->empreinteCode);
    }

    public function estExpire(): bool
    {
        return $this->dateExpiration <= new \DateTimeImmutable();
    }

    /** Vrai quand le nombre d'essais autorisés est atteint : le code ne vaut plus rien. */
    public function noterEssaiManque(): bool
    {
        return ++$this->essaisManques >= self::ESSAIS_MAX;
    }

    public function getEssaisRestants(): int
    {
        return max(0, self::ESSAIS_MAX - $this->essaisManques);
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateExpiration(): \DateTimeImmutable
    {
        return $this->dateExpiration;
    }
}
