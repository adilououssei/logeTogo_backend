<?php

namespace App\Repository;

use App\Entity\DemandeVerification;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeVerification>
 */
class DemandeVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeVerification::class);
    }

    public function derniere(Utilisateur $utilisateur): ?DemandeVerification
    {
        return $this->findOneBy(['utilisateur' => $utilisateur], ['id' => 'DESC']);
    }

    /** @return list<DemandeVerification> les plus anciennes d'abord */
    public function enAttente(): array
    {
        return $this->findBy(['statut' => DemandeVerification::EN_ATTENTE], ['id' => 'ASC']);
    }
}
