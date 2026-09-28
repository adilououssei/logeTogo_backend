<?php

namespace App\Service;

use App\Dto\InscriptionDto;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Crée un compte à partir des données d'inscription. */
class InscriptionUtilisateur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly ValidatorInterface $validateur,
        private readonly GenerateurIdentifiant $generateurIdentifiant,
        private readonly LockFactory $verrous,
    ) {
    }

    /**
     * @throws ValidationFailedException si les données sont refusées (email déjà utilisé…)
     */
    public function inscrire(InscriptionDto $donnees): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setPrenom(trim($donnees->prenom))
            ->setNom(trim($donnees->nom))
            ->setRegion($donnees->region)
            ->setRole($donnees->role)
            ->setEmail(null !== $donnees->email ? mb_strtolower(trim($donnees->email)) : null)
            ->setTelephone(null !== $donnees->telephone ? trim($donnees->telephone) : null)
            ->setWhatsapp(null !== $donnees->whatsapp ? trim($donnees->whatsapp) : null)
            ->setIndicatifPays($donnees->telephone || $donnees->whatsapp ? $donnees->indicatifPays : null);
        $utilisateur->setMotDePasse($this->hacheur->hashPassword($utilisateur, $donnees->motDePasse));

        // Deux inscriptions simultanées de « Kofi Mensah » ne doivent pas obtenir le même identifiant.
        $base = $this->generateurIdentifiant->base($donnees->prenom, $donnees->nom);
        $verrou = $this->verrous->createLock('inscription-identifiant-'.$base);
        $verrou->acquire(true);
        try {
            $utilisateur->setIdentifiant($this->generateurIdentifiant->generer($donnees->prenom, $donnees->nom));

            $violations = $this->validateur->validate($utilisateur);
            if (\count($violations) > 0) {
                throw new ValidationFailedException($utilisateur, $violations);
            }

            $this->em->persist($utilisateur);
            $this->em->flush();
        } finally {
            $verrou->release();
        }

        return $utilisateur;
    }
}
