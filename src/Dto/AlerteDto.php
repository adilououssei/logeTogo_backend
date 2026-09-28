<?php

namespace App\Dto;

use App\Enum\Region;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Critères d'une alerte (POST /api/alertes) : on est prévenu de chaque nouvelle annonce
 * qui y correspond. Un critère absent veut dire « peu importe ».
 */
final readonly class AlerteDto
{
    public function __construct(
        public ?Region $region = null,
        #[Assert\Length(max: 100)]
        public ?string $quartier = null,
        public ?TypeBien $typeBien = null,
        public ?TypeTransaction $typeTransaction = null,
        #[Assert\Positive(message: 'Le prix maximum doit être supérieur à 0.')]
        public ?int $prixMax = null,
    ) {
    }

    /** Sans aucun critère, l'utilisateur serait prévenu de toutes les annonces du pays. */
    #[Assert\Callback]
    public function validerCriteres(ExecutionContextInterface $contexte): void
    {
        if (null === $this->region && null === $this->typeBien && null === $this->typeTransaction && null === $this->prixMax && '' === trim((string) $this->quartier)) {
            $contexte->buildViolation('Choisissez au moins un critère (région, type de bien, prix…).')->addViolation();
        }
    }
}
