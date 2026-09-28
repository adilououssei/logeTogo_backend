<?php

namespace App\Service;

use App\Entity\DemandeVerification;
use App\Entity\Utilisateur;
use App\Repository\DemandeVerificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Vérification d'identité : dépôt de la pièce par l'utilisateur, décision par un
 * administrateur (commandes app:verifications:*). Les photos restent dans
 * var/verifications (jamais servies par le serveur web) et sont effacées à la décision.
 */
class VerificationIdentite
{
    private const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DemandeVerificationRepository $demandes,
        private readonly ValidatorInterface $validateur,
        private readonly Filesystem $systemeFichiers,
        private readonly Notificateur $notificateur,
        #[Autowire('%kernel.project_dir%/var/verifications')]
        private readonly string $dossier,
    ) {
    }

    /** @return array{verifie: bool, demande: array<string, mixed>|null, typesDocument: array<string, string>} */
    public function etat(Utilisateur $utilisateur): array
    {
        return [
            'verifie' => $utilisateur->isVerifie(),
            'demande' => $this->demandes->derniere($utilisateur)?->presenter(),
            'typesDocument' => DemandeVerification::TYPES_DOCUMENT,
        ];
    }

    public function demander(Utilisateur $utilisateur, string $typeDocument, ?UploadedFile $recto, ?UploadedFile $verso): DemandeVerification
    {
        if ($utilisateur->isVerifie()) {
            throw $this->erreur('typeDocument', 'Votre identité est déjà vérifiée.');
        }
        if ($this->demandes->derniere($utilisateur)?->estEnAttente()) {
            throw $this->erreur('typeDocument', 'Une demande est déjà en cours d\'examen.');
        }
        if (!\array_key_exists($typeDocument, DemandeVerification::TYPES_DOCUMENT)) {
            throw $this->erreur('typeDocument', 'Choisissez le type de pièce d\'identité.');
        }
        if (null === $recto) {
            throw $this->erreur('recto', 'Ajoutez la photo du recto de la pièce.');
        }
        $this->controlerImage($recto, 'recto');
        if (null !== $verso) {
            $this->controlerImage($verso, 'verso');
        }

        $demande = new DemandeVerification($utilisateur, $typeDocument);
        $sousDossier = (string) $utilisateur->getId();
        $demande->definirDocuments(
            $this->ranger($recto, $sousDossier),
            null !== $verso ? $this->ranger($verso, $sousDossier) : null,
        );
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }

    /** Décision de l'administrateur : badge accordé ou refus motivé, photos supprimées, utilisateur prévenu. */
    public function decider(DemandeVerification $demande, bool $approuvee, ?string $motif = null): void
    {
        if (!$demande->estEnAttente()) {
            throw new \LogicException('Cette demande a déjà été traitée.');
        }
        foreach ([$demande->getRecto(), $demande->getVerso()] as $chemin) {
            if (null !== $chemin) {
                $this->systemeFichiers->remove($this->dossier.'/'.$chemin);
            }
        }
        $demande->decider($approuvee, $motif);
        if ($approuvee) {
            $demande->getUtilisateur()->setVerifie(true);
        }
        $this->em->flush();

        $this->notificateur->decisionVerification($demande);
    }

    /** Chemin absolu d'une photo (pour l'administrateur). */
    public function cheminAbsolu(string $chemin): string
    {
        return $this->dossier.'/'.$chemin;
    }

    private function controlerImage(UploadedFile $fichier, string $champ): void
    {
        if (!\in_array($fichier->getMimeType(), self::TYPES_IMAGE, true)) {
            throw $this->erreur($champ, 'Envoyez une photo (JPEG, PNG, WebP, HEIC).');
        }
        foreach ($this->validateur->validate($fichier, new Assert\File(maxSize: '10M', maxSizeMessage: 'La photo dépasse 10 Mo.')) as $v) {
            throw $this->erreur($champ, (string) $v->getMessage());
        }
    }

    private function ranger(UploadedFile $fichier, string $sousDossier): string
    {
        $nom = bin2hex(random_bytes(12)).'.'.($fichier->guessExtension() ?? 'jpg');
        $fichier->move($this->dossier.'/'.$sousDossier, $nom);

        return $sousDossier.'/'.$nom;
    }

    private function erreur(string $champ, string $texte): ValidationFailedException
    {
        return new ValidationFailedException(null, new ConstraintViolationList([
            new ConstraintViolation($texte, $texte, [], null, $champ, null),
        ]));
    }
}
