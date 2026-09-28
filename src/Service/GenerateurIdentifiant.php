<?php

namespace App\Service;

use App\Repository\UtilisateurRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Construit l'identifiant de connexion à partir du prénom et du nom :
 * « Élodie Adjo-Mensah » → « elodie.adjomensah », puis « elodie.adjomensah2 »,
 * « elodie.adjomensah3 »… si l'identifiant est déjà pris.
 */
class GenerateurIdentifiant
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    /** Partie « prenom.nom » sans accents, espaces ni caractères spéciaux (vide si aucune lettre). */
    public function base(string $prenom, string $nom): string
    {
        $nettoyer = fn (string $texte): string => (string) preg_replace('/[^a-z0-9]/', '', $this->slugger->slug($texte, '')->lower()->toString());

        return implode('.', array_filter([$nettoyer($prenom), $nettoyer($nom)]));
    }

    public function generer(string $prenom, string $nom): string
    {
        $base = $this->base($prenom, $nom);
        if ('' === $base) {
            throw new \InvalidArgumentException('Le prénom et le nom doivent contenir des lettres.');
        }

        $pris = array_flip($this->utilisateurs->trouverIdentifiantsCommencantPar($base));
        if (!isset($pris[$base])) {
            return $base;
        }

        $numero = 2;
        while (isset($pris[$base.$numero])) {
            ++$numero;
        }

        return $base.$numero;
    }
}
