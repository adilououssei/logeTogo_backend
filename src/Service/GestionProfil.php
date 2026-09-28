<?php

namespace App\Service;

use App\Dto\ProfilDto;
use App\Entity\Utilisateur;
use App\Repository\JetonRenouvellementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Modification du profil, de la photo et du mot de passe de l'utilisateur connecté. */
class GestionProfil
{
    private const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validateur,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly JetonRenouvellementRepository $jetonsRenouvellement,
        private readonly Filesystem $systemeFichiers,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $dossierPublic,
    ) {
    }

    public function modifier(Utilisateur $utilisateur, ProfilDto $donnees): Utilisateur
    {
        $telephone = null !== $donnees->telephone ? trim($donnees->telephone) : '';
        $whatsapp = null !== $donnees->whatsapp ? trim($donnees->whatsapp) : '';
        $utilisateur
            ->setPrenom(trim($donnees->prenom))
            ->setNom(trim($donnees->nom))
            ->setRegion($donnees->region)
            ->setEmail(null !== $donnees->email ? mb_strtolower(trim($donnees->email)) : null)
            ->setTelephone($telephone)
            ->setWhatsapp($whatsapp)
            ->setIndicatifPays('' !== $telephone || '' !== $whatsapp ? $donnees->indicatifPays : null);

        $violations = $this->validateur->validate($utilisateur);
        if (\count($violations) > 0) {
            // L'email appartient déjà à quelqu'un d'autre, par exemple : rien n'est enregistré.
            $this->em->refresh($utilisateur);
            throw new ValidationFailedException($utilisateur, $violations);
        }
        $this->em->flush();

        return $utilisateur;
    }

    /**
     * Change le mot de passe et déconnecte les autres appareils (leurs jetons de
     * renouvellement sont supprimés) : utile si le compte a été utilisé par quelqu'un d'autre.
     */
    public function changerMotDePasse(Utilisateur $utilisateur, string $actuel, string $nouveau): void
    {
        if (!$this->hacheur->isPasswordValid($utilisateur, $actuel)) {
            throw $this->erreur('motDePasseActuel', 'Mot de passe actuel incorrect.');
        }
        $utilisateur->setMotDePasse($this->hacheur->hashPassword($utilisateur, $nouveau));
        $this->em->flush();

        $this->jetonsRenouvellement->createQueryBuilder('j')
            ->delete()
            ->andWhere('j.username = :identifiant')
            ->setParameter('identifiant', $utilisateur->getUserIdentifier())
            ->getQuery()
            ->execute();
    }

    /** Remplace la photo de profil (5 Mo au plus). */
    public function changerAvatar(Utilisateur $utilisateur, ?UploadedFile $fichier): Utilisateur
    {
        if (null === $fichier) {
            throw $this->erreur('fichier', 'Aucune photo reçue.');
        }
        if (!\in_array($fichier->getMimeType(), self::TYPES_IMAGE, true)) {
            throw $this->erreur('fichier', 'Format non accepté : envoyez une photo (JPEG, PNG, WebP, HEIC).');
        }
        foreach ($this->validateur->validate($fichier, new Assert\File(maxSize: '5M', maxSizeMessage: 'La photo dépasse 5 Mo.')) as $v) {
            throw $this->erreur('fichier', (string) $v->getMessage());
        }

        $this->supprimerFichierAvatar($utilisateur);
        $nom = bin2hex(random_bytes(12)).'.'.($fichier->guessExtension() ?? 'jpg');
        $fichier->move(\sprintf('%s/uploads/avatars/%d', $this->dossierPublic, $utilisateur->getId()), $nom);
        $utilisateur->setAvatar(\sprintf('/uploads/avatars/%d/%s', $utilisateur->getId(), $nom));
        $this->em->flush();

        return $utilisateur;
    }

    public function retirerAvatar(Utilisateur $utilisateur): Utilisateur
    {
        $this->supprimerFichierAvatar($utilisateur);
        $utilisateur->setAvatar(null);
        $this->em->flush();

        return $utilisateur;
    }

    /** Les avatars externes (comptes de démonstration) ne sont pas des fichiers locaux. */
    private function supprimerFichierAvatar(Utilisateur $utilisateur): void
    {
        $avatar = $utilisateur->getAvatar();
        if (null !== $avatar && str_starts_with($avatar, '/uploads/avatars/')) {
            $this->systemeFichiers->remove($this->dossierPublic.$avatar);
        }
    }

    private function erreur(string $champ, string $texte): ValidationFailedException
    {
        return new ValidationFailedException(null, new ConstraintViolationList([
            new ConstraintViolation($texte, $texte, [], null, $champ, null),
        ]));
    }
}
