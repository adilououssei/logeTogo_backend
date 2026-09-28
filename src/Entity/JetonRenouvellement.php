<?php

namespace App\Entity;

use App\Repository\JetonRenouvellementRepository;
use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;

/**
 * Jeton de renouvellement (« refresh token ») : permet à l'application d'obtenir
 * un nouveau jeton d'accès sans redemander le mot de passe. Il est remplacé à
 * chaque utilisation et supprimé à la déconnexion. Seule son empreinte est stockée.
 */
#[ORM\Entity(repositoryClass: JetonRenouvellementRepository::class)]
#[ORM\Table(name: 'jeton_renouvellement')]
#[ORM\Index(name: 'idx_jeton_renouvellement_famille', fields: ['family'])]
class JetonRenouvellement extends RefreshToken
{
}
