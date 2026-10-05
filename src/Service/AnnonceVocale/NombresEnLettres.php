<?php

namespace App\Service\AnnonceVocale;

/**
 * Remplace les nombres dits ou mal écrits par des chiffres, dans un texte dicté :
 *   « vingt-cinq mille »            → « 25000 »
 *   « deux cent cinquante mille »   → « 250000 »
 *   « un million deux cent mille »  → « 1200000 »
 *   « 25 000 », « 25.000 »          → « 25000 »
 *   « 25 mille », « 1,5 million »   → « 25000 », « 1500000 »
 * « un » / « une » seuls restent des mots (« une chambre », « un forage »).
 */
final class NombresEnLettres
{
    private const UNITES = [
        'zero' => 0, 'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6,
        'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10, 'onze' => 11, 'douze' => 12, 'treize' => 13,
        'quatorze' => 14, 'quinze' => 15, 'seize' => 16, 'vingt' => 20, 'vingts' => 20, 'trente' => 30,
        'quarante' => 40, 'cinquante' => 50, 'soixante' => 60, 'septante' => 70, 'huitante' => 80, 'nonante' => 90,
    ];
    private const MULTIPLICATEURS = ['cent' => 100, 'cents' => 100, 'mille' => 1000, 'milles' => 1000, 'million' => 1_000_000, 'millions' => 1_000_000, 'milliard' => 1_000_000_000, 'milliards' => 1_000_000_000];

    public static function convertir(string $texte): string
    {
        // « 25 000 », « 1.500.000 » : séparateurs de milliers retirés.
        $texte = preg_replace_callback('/\b\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})+\b/u', fn ($m) => preg_replace('/\D/', '', $m[0]), $texte);
        // « 1,5 million » → 1500000
        $texte = preg_replace_callback('/\b(\d+)[,.](\d+)\s*(millions?|mille)\b/iu', function ($m) {
            $facteur = str_starts_with(mb_strtolower($m[3]), 'million') ? 1_000_000 : 1000;

            return (string) (int) round((float) ($m[1].'.'.$m[2]) * $facteur);
        }, $texte);

        // Suites de mots-nombres (et de chiffres suivis de « mille », « million »…).
        $mot = '(?:\d+|'.implode('|', array_map('preg_quote', array_keys(self::UNITES + self::MULTIPLICATEURS))).')';
        $motif = '/(?<![\p{L}\d])'.$mot.'(?:(?:[\s-]+(?:et[\s-]+)?)'.$mot.')*(?![\p{L}\d])/iu';

        return preg_replace_callback($motif, function (array $m): string {
            $jetons = preg_split('/[\s-]+/u', mb_strtolower($m[0])) ?: [];
            $jetons = array_values(array_filter($jetons, fn ($j) => 'et' !== $j));
            // « un » / « une » seuls, ou un chiffre seul : rien à convertir.
            if (1 === \count($jetons) && (\in_array($jetons[0], ['un', 'une'], true) || ctype_digit($jetons[0]))) {
                return $m[0];
            }

            return (string) self::valeur($jetons);
        }, $texte) ?? $texte;
    }

    /** @param list<string> $jetons */
    private static function valeur(array $jetons): int
    {
        $total = 0;
        $courant = 0;
        $precedent = null;
        foreach ($jetons as $jeton) {
            if (ctype_digit($jeton)) {
                $courant += (int) $jeton;
            } elseif (isset(self::UNITES[$jeton])) {
                $v = self::UNITES[$jeton];
                // « quatre-vingt(s) » = 80 (et non 4 + 20).
                if (20 === $v && 'quatre' === $precedent) {
                    $courant += 76;
                } else {
                    $courant += $v;
                }
            } else {
                $multiplicateur = self::MULTIPLICATEURS[$jeton];
                if (100 === $multiplicateur) {
                    $courant = max(1, $courant) * 100;
                } else {
                    $total += max(1, $courant) * $multiplicateur;
                    $courant = 0;
                }
            }
            $precedent = $jeton;
        }

        return $total + $courant;
    }
}
