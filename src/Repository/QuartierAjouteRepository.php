<?php

namespace App\Repository;

use App\Entity\QuartierAjoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuartierAjoute>
 */
class QuartierAjouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuartierAjoute::class);
    }
}
