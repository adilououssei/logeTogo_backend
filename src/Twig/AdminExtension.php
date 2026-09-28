<?php

namespace App\Twig;

use App\Entity\Annonce;
use App\Enum\TypeMedia;
use App\Service\StatistiquesAdmin;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Aides d'affichage de l'interface d'administration. */
class AdminExtension
{
    /** @var array{signalements: int, verifications: int}|null calculé une fois par requête */
    private ?array $compteurs = null;

    public function __construct(private readonly StatistiquesAdmin $statistiques)
    {
    }

    /** Éléments en attente (pastilles de la barre latérale et de la cloche). */
    #[AsTwigFunction('admin_a_traiter')]
    public function aTraiter(): array
    {
        return $this->compteurs ??= [
            'signalements' => $this->statistiques->signalementsEnAttente(),
            'verifications' => $this->statistiques->verificationsEnAttente(),
        ];
    }

    /** « à l'instant », « il y a 12 min », « il y a 3 h », « il y a 2 j », puis la date. */
    #[AsTwigFilter('il_y_a')]
    public function ilYA(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }
        $secondes = time() - $date->getTimestamp();

        return match (true) {
            $secondes < 60 => 'à l\'instant',
            $secondes < 3600 => \sprintf('il y a %d min', intdiv($secondes, 60)),
            $secondes < 86400 => \sprintf('il y a %d h', intdiv($secondes, 3600)),
            $secondes < 7 * 86400 => \sprintf('il y a %d j', intdiv($secondes, 86400)),
            default => $date->format('d/m/Y'),
        };
    }

    /** 450000 → « 450 000 FCFA » */
    #[AsTwigFilter('fcfa')]
    public function fcfa(?int $montant): string
    {
        return null === $montant ? '—' : number_format($montant, 0, ',', ' ').' FCFA';
    }

    /** « Kofi Mensah » → « KM » (avatar sans photo). */
    #[AsTwigFilter('initiales')]
    public function initiales(?string $nom): string
    {
        $mots = preg_split('/\s+/', trim((string) $nom)) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($m) => mb_substr($m, 0, 1), \array_slice($mots, 0, 2))));
    }

    /** Première photo de l'annonce, sinon null. */
    #[AsTwigFunction('photo_principale')]
    public function photoPrincipale(Annonce $annonce): ?string
    {
        foreach ($annonce->getMedias() as $media) {
            if (TypeMedia::IMAGE === $media->getType()) {
                return $media->getUrl();
            }
        }

        return null;
    }
}
