<?php

namespace App\Repository;

use App\Entity\JetonRenouvellement;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository;

/**
 * @extends RefreshTokenRepository<JetonRenouvellement>
 */
class JetonRenouvellementRepository extends RefreshTokenRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(JetonRenouvellement::class));
    }
}
