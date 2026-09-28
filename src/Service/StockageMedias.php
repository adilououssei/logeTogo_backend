<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Media;
use App\Enum\TypeMedia;
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
 * Photos et vidéos des annonces, enregistrées dans public/uploads/annonces/{id}/.
 * L'URL stockée est relative (/uploads/…) : l'application la complète avec l'adresse du serveur.
 */
class StockageMedias
{
    public const MAX_PHOTOS = 8;
    public const MAX_VIDEOS = 3;

    private const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    private const TYPES_VIDEO = ['video/mp4', 'video/quicktime', 'video/3gpp', 'video/webm'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validateur,
        private readonly Filesystem $systemeFichiers,
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $dossierPublic,
    ) {
    }

    /**
     * @param list<UploadedFile> $fichiers
     *
     * @return list<Media> les médias ajoutés
     *
     * @throws ValidationFailedException fichier invalide ou trop de photos/vidéos
     */
    public function ajouter(Annonce $annonce, array $fichiers): array
    {
        $violations = new ConstraintViolationList();
        if ([] === $fichiers) {
            $violations->add($this->violation('fichiers', 'Aucun fichier reçu.'));
        }

        $photos = $annonce->getMedias()->filter(fn (Media $m) => TypeMedia::IMAGE === $m->getType())->count();
        $videos = $annonce->getMedias()->count() - $photos;
        $types = [];
        foreach ($fichiers as $i => $fichier) {
            $type = $this->typeDe($fichier);
            $chemin = "fichiers[$i]";
            if (null === $type) {
                $violations->add($this->violation($chemin, 'Format non accepté : envoyez une photo (JPEG, PNG, WebP, HEIC) ou une vidéo (MP4, MOV).'));
                continue;
            }
            $contrainte = new Assert\File(
                maxSize: TypeMedia::IMAGE === $type ? '10M' : '100M',
                maxSizeMessage: TypeMedia::IMAGE === $type ? 'La photo dépasse 10 Mo.' : 'La vidéo dépasse 100 Mo.',
            );
            foreach ($this->validateur->validate($fichier, $contrainte) as $v) {
                $violations->add($this->violation($chemin, (string) $v->getMessage()));
            }
            TypeMedia::IMAGE === $type ? ++$photos : ++$videos;
            $types[$i] = $type;
        }
        if ($photos > self::MAX_PHOTOS) {
            $violations->add($this->violation('fichiers', \sprintf('%d photos au maximum par annonce.', self::MAX_PHOTOS)));
        }
        if ($videos > self::MAX_VIDEOS) {
            $violations->add($this->violation('fichiers', \sprintf('%d vidéos au maximum par annonce.', self::MAX_VIDEOS)));
        }
        if (\count($violations) > 0) {
            throw new ValidationFailedException($fichiers, $violations);
        }

        $dossier = $this->dossierAnnonce($annonce);
        $ordre = $annonce->getMedias()->count();
        $ajoutes = [];
        foreach ($fichiers as $i => $fichier) {
            $nom = bin2hex(random_bytes(12)).'.'.($fichier->guessExtension() ?? 'bin');
            $fichier->move($dossier, $nom);

            $media = (new Media())
                ->setUrl(\sprintf('/uploads/annonces/%d/%s', $annonce->getId(), $nom))
                ->setType($types[$i])
                ->setOrdre($ordre++);
            $annonce->addMedia($media);
            $ajoutes[] = $media;
        }
        $this->em->flush();

        return $ajoutes;
    }

    public function supprimer(Media $media): void
    {
        $this->supprimerFichier($media->getUrl());
        $media->getAnnonce()?->removeMedia($media);
        $this->em->remove($media);
        $this->em->flush();
    }

    public function supprimerDossierAnnonce(Annonce $annonce): void
    {
        if (null !== $annonce->getId()) {
            $this->systemeFichiers->remove($this->dossierAnnonce($annonce));
        }
    }

    private function typeDe(UploadedFile $fichier): ?TypeMedia
    {
        $mime = $fichier->getMimeType();

        return match (true) {
            \in_array($mime, self::TYPES_IMAGE, true) => TypeMedia::IMAGE,
            \in_array($mime, self::TYPES_VIDEO, true) => TypeMedia::VIDEO,
            default => null,
        };
    }

    private function dossierAnnonce(Annonce $annonce): string
    {
        return \sprintf('%s/uploads/annonces/%d', $this->dossierPublic, $annonce->getId());
    }

    /** Les médias externes (URL http…) des données de démonstration ne sont pas des fichiers locaux. */
    private function supprimerFichier(?string $url): void
    {
        if (null !== $url && str_starts_with($url, '/uploads/')) {
            $this->systemeFichiers->remove($this->dossierPublic.$url);
        }
    }

    private function violation(string $chemin, string $message): ConstraintViolation
    {
        return new ConstraintViolation($message, $message, [], null, $chemin, null);
    }
}
