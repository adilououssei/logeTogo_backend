<?php

namespace App\Command;

use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un compte administrateur (ou donne le rôle administrateur à un compte existant) :
 *   php bin/console app:creer-admin admin.logetogo
 * Le mot de passe est demandé sans être affiché.
 */
#[AsCommand(name: 'app:creer-admin', description: 'Crée un compte administrateur pour l\'interface /admin')]
class CreerAdminCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Identifiant de connexion, ex. admin.logetogo')] string $identifiant,
    ): int {
        $identifiant = UtilisateurRepository::normaliserIdentifiant($identifiant);
        $utilisateur = $this->utilisateurs->loadUserByIdentifier($identifiant);

        if (null !== $utilisateur) {
            if (!$io->confirm(\sprintf('Le compte « %s » (%s) existe : lui donner le rôle administrateur ?', $identifiant, $utilisateur->getRole()->libelle()), false)) {
                return Command::FAILURE;
            }
        } else {
            $utilisateur = (new Utilisateur())
                ->setIdentifiant($identifiant)
                ->setPrenom((string) $io->ask('Prénom', 'Administrateur'))
                ->setNom((string) $io->ask('Nom', 'LogeTogo'))
                ->setRegion(Region::MARITIME);
            $this->em->persist($utilisateur);
        }

        $motDePasse = (string) $io->askHidden('Mot de passe (8 caractères au moins)', function (?string $valeur) {
            if (null === $valeur || mb_strlen($valeur) < 8) {
                throw new \RuntimeException('Au moins 8 caractères.');
            }

            return $valeur;
        });
        $utilisateur->setRole(RoleUtilisateur::ADMIN)->setEstActif(true);
        $utilisateur->setMotDePasse($this->hacheur->hashPassword($utilisateur, $motDePasse));
        $this->em->flush();

        $io->success(\sprintf('Administrateur « %s » prêt. Connexion : /admin/connexion', $identifiant));

        return Command::SUCCESS;
    }
}
