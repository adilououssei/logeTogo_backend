<?php

namespace App\Service\AnnonceVocale;

use App\Enum\Region;

/**
 * Villes et quartiers du Togo reconnus dans une annonce dictée ou saisie.
 *
 * Les quartiers viennent de la liste officielle config/lieux/quartiers_togo.csv
 * (« Quartier;Ville;Autres façons de dire ou d'écrire;Prioritaire »), complétée par
 * les quartiers ajoutés par les agents (voir ReferentielQuartiers).
 * Comparaison sans accents ni majuscules (« Agoe », « AGOÈ » et « Agoè » se valent).
 *
 * @phpstan-type Quartier array{nom: string, ville: string, variantes: list<string>, prioritaire: bool}
 */
final class LieuxTogo
{
    /** Ville => région */
    public const VILLES = [
        'Lomé' => Region::MARITIME, 'Tsévié' => Region::MARITIME, 'Aného' => Region::MARITIME, 'Vogan' => Region::MARITIME,
        'Tabligbo' => Region::MARITIME, 'Kévé' => Region::MARITIME, 'Agbodrafo' => Region::MARITIME,
        'Kpalimé' => Region::PLATEAUX, 'Atakpamé' => Region::PLATEAUX, 'Notsé' => Region::PLATEAUX, 'Badou' => Region::PLATEAUX,
        'Amlamé' => Region::PLATEAUX, 'Anié' => Region::PLATEAUX, 'Kougnohou' => Region::PLATEAUX,
        'Sokodé' => Region::CENTRALE, 'Tchamba' => Region::CENTRALE, 'Sotouboua' => Region::CENTRALE, 'Blitta' => Region::CENTRALE,
        'Kara' => Region::KARA, 'Bassar' => Region::KARA, 'Niamtougou' => Region::KARA, 'Pagouda' => Region::KARA,
        'Bafilo' => Region::KARA, 'Kandé' => Region::KARA, 'Guérin-Kouka' => Region::KARA,
        'Dapaong' => Region::SAVANES, 'Mango' => Region::SAVANES, 'Cinkassé' => Region::SAVANES, 'Tandjouaré' => Region::SAVANES,
    ];

    private const FICHIER_QUARTIERS = __DIR__.'/../../../config/lieux/quartiers_togo.csv';

    /**
     * Autres noms qui sont aussi des mots courants (« château d'eau », « près du grand marché ») :
     * proposés en suggestion, mais jamais pris pour un quartier dans un texte libre.
     */
    private const VARIANTES_AMBIGUES = ['chateau', 'solidarite', 'grand marche', 'casablanca', 'forever', 'habitat', 'lycee', 'oua', 'zongo'];

    /** Petits mots entre deux parties d'un nom (« Agoè, à Assiyéyé ») ou qui précèdent un lieu. */
    private const LIAISONS = ['a', 'au', 'et', 'de', 'du', 'vers', 'quartier', 'cote'];

    /** Ressemblance minimale (0 à 1) de prononciation pour corriger un nom mal transcrit. */
    private const RESSEMBLANCE_MIN = 0.75;

    /** @var list<Quartier>|null */
    private static ?array $officiels = null;

    /**
     * Quartiers de la liste officielle.
     *
     * @return list<Quartier>
     */
    public static function quartiersOfficiels(): array
    {
        if (null !== self::$officiels) {
            return self::$officiels;
        }
        $lignes = file(self::FICHIER_QUARTIERS, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        array_shift($lignes); // en-tête
        $quartiers = [];
        foreach ($lignes as $ligne) {
            $colonnes = array_map('trim', str_getcsv($ligne, ';', '"', ''));
            if ('' === ($colonnes[0] ?? '') || '' === ($colonnes[1] ?? '')) {
                continue;
            }
            $quartiers[] = [
                'nom' => $colonnes[0],
                'ville' => $colonnes[1],
                'variantes' => array_values(array_filter(array_map('trim', explode(',', $colonnes[2] ?? '')))),
                'prioritaire' => 'oui' === mb_strtolower($colonnes[3] ?? ''),
            ];
        }

        return self::$officiels = $quartiers;
    }

    /**
     * Quartier (avec le nom qui le suit, ex. « Agoè Assiyéyé »), ville et région trouvés dans le texte.
     *
     * @param list<Quartier> $ajoutes quartiers ajoutés par les agents
     *
     * @return array{quartier: ?string, ville: ?string, region: ?Region}
     */
    public static function trouver(string $texte, array $ajoutes = []): array
    {
        $normal = self::normaliser($texte);
        $cles = self::cles($ajoutes, sansAmbigus: true);
        $quartier = null;
        $ville = null;

        // Le quartier cité en premier ; à même position, le nom le plus long (« Agoè Assiyéyé » plutôt que « Agoè »).
        $trouve = null;
        foreach (array_keys($cles) as $cle) {
            $position = self::position($normal, (string) $cle);
            if (null !== $position && (null === $trouve || $position < $trouve[0] || ($position === $trouve[0] && \strlen((string) $cle) > \strlen($trouve[1])))) {
                $trouve = [$position, (string) $cle];
            }
        }
        if (null !== $trouve) {
            [$quartier, $ville] = self::avecComplement($texte, $normal, $trouve[0], $trouve[1], $cles);
        }

        $villes = array_keys(self::VILLES);
        usort($villes, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($villes as $nom) {
            if (null !== self::position($normal, self::normaliser($nom))) {
                // Une ville citée explicitement l'emporte, sauf si c'est le même lieu que le quartier.
                $ville = $nom === $quartier ? ($ville ?? $nom) : $nom;
                break;
            }
        }

        return ['quartier' => $quartier, 'ville' => $ville, 'region' => null !== $ville ? (self::VILLES[$ville] ?? null) : null];
    }

    /**
     * Nom officiel d'un quartier saisi (« agoe assiyeye » → « Agoè Assiyéyé ») ; null s'il est inconnu.
     *
     * @param list<Quartier> $ajoutes
     *
     * @return array{nom: string, ville: string}|null
     */
    public static function reconnaitre(string $quartier, array $ajoutes = []): ?array
    {
        return self::cles($ajoutes)[self::normaliser($quartier)] ?? null;
    }

    /**
     * Quartiers dont un mot commence par le début saisi (« ago » → Agoè, Agoè Assiyéyé…) :
     * d'abord ceux qui commencent par ce début, de la même ville, les plus demandés.
     *
     * @param list<Quartier> $ajoutes
     *
     * @return list<array{nom: string, ville: string, region: ?Region}>
     */
    public static function suggerer(string $debut, array $ajoutes = [], ?string $ville = null, int $limite = 8): array
    {
        $debut = self::normaliser($debut);
        if ('' === $debut) {
            return [];
        }
        $villeNormale = null !== $ville && '' !== trim($ville) ? self::normaliser($ville) : null;
        $resultats = [];
        foreach ([...self::quartiersOfficiels(), ...$ajoutes] as $q) {
            $rang = null;
            foreach ([$q['nom'], ...$q['variantes']] as $forme) {
                $f = self::normaliser($forme);
                if (str_starts_with($f, $debut)) {
                    $rang = 0;
                    break;
                }
                if (str_contains(' '.$f, ' '.$debut)) {
                    $rang = 1;
                }
            }
            if (null === $rang || isset($resultats[$q['nom']])) {
                continue;
            }
            $memeVille = null === $villeNormale || self::normaliser($q['ville']) === $villeNormale ? 0 : 1;
            $resultats[$q['nom']] = [[$rang, $memeVille, $q['prioritaire'] ? 0 : 1, self::normaliser($q['nom'])], $q];
        }
        uasort($resultats, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_values(array_map(
            fn ($r) => ['nom' => $r[1]['nom'], 'ville' => $r[1]['ville'], 'region' => self::VILLES[$r[1]['ville']] ?? null],
            \array_slice($resultats, 0, $limite),
        ));
    }

    /**
     * Corrige les noms de quartiers mal transcrits par Whisper en les comparant « au son »
     * aux noms connus : « à Gwai » → « à Agoè », « à Seye » → « à Assiyéyé ».
     * Seuls les noms propres (majuscule) et les mots qui suivent « à », « au », « quartier »… sont examinés.
     *
     * @param list<Quartier> $ajoutes
     */
    public static function corrigerNoms(string $texte, array $ajoutes = []): string
    {
        // Prononciation => forme affichée (le nom, ou l'autre façon de l'écrire qui se prononce ainsi).
        $sons = [];
        foreach ([...self::quartiersOfficiels(), ...$ajoutes] as $q) {
            foreach ([$q['nom'], ...$q['variantes']] as $forme) {
                $son = self::son($forme);
                if (\strlen($son) >= 4 && !\in_array(self::normaliser($forme), self::VARIANTES_AMBIGUES, true)) {
                    $sons[$son] ??= $forme;
                }
            }
        }
        $connus = self::cles($ajoutes);
        foreach (array_keys(self::VILLES) as $v) {
            $connus[self::normaliser($v)] = ['nom' => $v, 'ville' => $v];
        }

        preg_match_all('/[\p{L}\p{M}]+(?:[\'’-][\p{L}\p{M}]+)*/u', $texte, $m, \PREG_OFFSET_CAPTURE);
        $mots = $m[0];
        $corrections = [];
        $finPrecedente = -1;
        for ($i = 0, $n = \count($mots); $i < $n; ++$i) {
            if ($mots[$i][1] < $finPrecedente) {
                continue;
            }
            $meilleure = null;
            for ($taille = 1; $taille <= 3 && $i + $taille <= $n; ++$taille) {
                $fenetre = \array_slice($mots, $i, $taille);
                if (!self::sansPonctuation($texte, $fenetre)) {
                    break;
                }
                $correction = self::rapprocher($fenetre, $i > 0 ? $mots[$i - 1][0] : null, $sons, $connus);
                if (null !== $correction && (null === $meilleure || $correction['score'] > $meilleure['score'])) {
                    $meilleure = $correction;
                }
            }
            if (null !== $meilleure) {
                $corrections[] = $meilleure;
                $finPrecedente = $meilleure['debut'] + $meilleure['longueur'];
            }
        }

        // Remplacements de la fin vers le début : les positions restent valables.
        foreach (array_reverse($corrections) as $c) {
            $texte = substr_replace($texte, $c['remplacement'], $c['debut'], $c['longueur']);
        }

        return $texte;
    }

    /**
     * Texte d'amorce pour Whisper : il recopie l'orthographe des noms qu'il y voit.
     * Limité en longueur, Whisper ne gardant que la fin d'une amorce trop longue (~220 jetons) :
     * on y met les quartiers prioritaires, puis les villes.
     */
    public static function indiceTranscription(): string
    {
        $prioritaires = array_column(array_filter(self::quartiersOfficiels(), fn ($q) => $q['prioritaire']), 'nom');
        $noms = mb_substr(implode(', ', [...$prioritaires, ...array_keys(self::VILLES)]), 0, 600);

        return 'Annonce immobilière au Togo, prix en francs CFA. '.mb_substr($noms, 0, (int) mb_strrpos($noms, ',')).'.';
    }

    public static function normaliser(string $texte): string
    {
        $sansAccents = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texte);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) $sansAccents));
    }

    /**
     * Prononciation simplifiée : les écritures qui se disent pareil donnent le même résultat
     * (« Agoè » et « à Gwai » → « ague » ; « Assiyéyé » et « Asiyeye » → « asieie »).
     */
    public static function son(string $texte): string
    {
        $s = str_replace(' ', '', self::normaliser($texte));
        $s = (string) preg_replace(
            // Groupes de voyelles avant « w » et « y » : « Gwai » → « gwe » → « gue ».
            ['/ph/', '/qu/', '/gu(?=[eiy])/', '/c(?=[eiy])/', '/ck|c/', '/x/', '/tch/', '/dj/', '/dz|z/', '/h/', '/eau|au/', '/ou/', '/oi/', '/oe/', '/ai|ei/', '/w/', '/y/'],
            ['f', 'k', 'g', 's', 'k', 'ks', 'ch', 'j', 's', '', 'o', 'u', 'ua', 'ue', 'e', 'u', 'i'],
            $s,
        );

        return (string) preg_replace('/(.)\1+/', '$1', $s); // lettres doublées : « Assiyéyé » = « Asiyéyé »
    }

    /**
     * Toutes les façons d'écrire un quartier (sans accents) => nom officiel et ville.
     *
     * @param list<Quartier> $ajoutes
     *
     * @return array<string, array{nom: string, ville: string}>
     */
    private static function cles(array $ajoutes = [], bool $sansAmbigus = false): array
    {
        $cles = [];
        foreach ([...self::quartiersOfficiels(), ...$ajoutes] as $q) {
            $cles[self::normaliser($q['nom'])] ??= ['nom' => $q['nom'], 'ville' => $q['ville']];
            foreach ($q['variantes'] as $variante) {
                $cle = self::normaliser($variante);
                if (!$sansAmbigus || !\in_array($cle, self::VARIANTES_AMBIGUES, true)) {
                    $cles[$cle] ??= ['nom' => $q['nom'], 'ville' => $q['ville']];
                }
            }
        }
        unset($cles['']);

        return $cles;
    }

    private static function position(string $texteNormal, string $cle): ?int
    {
        $motif = '/(?<![a-z0-9])'.preg_quote($cle, '/').'(?![a-z0-9])/';

        return preg_match($motif, $texteNormal, $m, \PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
    }

    /**
     * « Agoè Assiyéyé » : ce qui suit le quartier (après « , », « à »…) est gardé s'il complète
     * son nom (« Assiyéyé » → « Agoè Assiyéyé »), s'il s'agit d'un autre quartier connu, ou d'un nom
     * propre (majuscule dans la transcription).
     *
     * @param array<string, array{nom: string, ville: string}> $cles
     *
     * @return array{0: string, 1: string} quartier et ville
     */
    private static function avecComplement(string $texte, string $normal, int $position, string $cle, array $cles): array
    {
        ['nom' => $nom, 'ville' => $ville] = $cles[$cle];
        $suite = array_values(array_filter(
            explode(' ', substr($normal, $position + \strlen($cle))),
            fn ($mot) => '' !== $mot && !\in_array($mot, self::LIAISONS, true),
        ));
        foreach ([2, 1] as $taille) { // complément en deux mots (« Tokoin Hôpital ») avant un seul
            if (\count($suite) < $taille) {
                continue;
            }
            $complement = $cles[implode(' ', \array_slice($suite, 0, $taille))] ?? null;
            if (null === $complement) {
                continue;
            }
            if (str_starts_with(self::normaliser($complement['nom']), self::normaliser($nom).' ')) {
                return [$complement['nom'], $complement['ville']];
            }
            if ($complement['nom'] !== $nom && !str_contains($nom, $complement['nom'])) {
                return [$nom.' '.$complement['nom'], $ville];
            }
        }
        // Nom propre dans le texte d'origine (ex. transcription « Agoè Kpogli »).
        if (preg_match('/'.preg_quote($nom, '/').'[\s-]+(\p{Lu}[\p{L}-]{2,})/u', $texte, $m) && !\in_array(mb_strtolower($m[1]), ['il', 'elle', 'le', 'la', 'les', 'c', 'ce', 'cest'], true)) {
            return [$nom.' '.$m[1], $ville];
        }

        return [$nom, $ville];
    }

    /**
     * @param list<array{0: string, 1: int}> $fenetre
     */
    private static function sansPonctuation(string $texte, array $fenetre): bool
    {
        for ($k = 1, $n = \count($fenetre); $k < $n; ++$k) {
            $finMot = $fenetre[$k - 1][1] + \strlen($fenetre[$k - 1][0]);
            if ('' !== trim(substr($texte, $finMot, $fenetre[$k][1] - $finMot))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nom connu qui se prononce comme la suite de mots, si elle désigne probablement un lieu.
     *
     * @param list<array{0: string, 1: int}>                   $fenetre
     * @param array<string, string>                            $sons
     * @param array<string, array{nom: string, ville: string}> $connus
     *
     * @return array{debut: int, longueur: int, remplacement: string, score: float}|null
     */
    private static function rapprocher(array $fenetre, ?string $motPrecedent, array $sons, array $connus): ?array
    {
        $premier = $fenetre[0][0];
        $estA = 'a' === self::normaliser($premier);
        $apresLiaison = null !== $motPrecedent && \in_array(self::normaliser($motPrecedent), self::LIAISONS, true);
        // Un lieu : nom propre (majuscule), mot qui suit « à »… ou « à » collé au nom (« à Gwai » pour « Agoè »).
        $nomPropre = $estA ? isset($fenetre[1]) && preg_match('/^\p{Lu}/u', $fenetre[1][0]) : preg_match('/^\p{Lu}/u', $premier);
        if (!$nomPropre && !$apresLiaison) {
            return null;
        }
        if ($estA && 1 === \count($fenetre)) {
            return null;
        }
        $mots = implode(' ', array_column($fenetre, 0));
        if (isset($connus[self::normaliser($mots)])) {
            return null; // déjà bien écrit
        }
        // Un lieu déjà bien écrit n'est remplacé que par un nom qui le contient (« Tokoin Opital » → « Tokoin Hôpital »).
        $motsConnus = array_filter(array_map(fn ($f) => self::normaliser($f[0]), $fenetre), fn ($mot) => isset($connus[$mot]));
        $son = self::son($mots);
        if (\strlen($son) < 4) {
            return null;
        }
        $meilleur = null;
        foreach ($sons as $sonConnu => $forme) {
            $sonConnu = (string) $sonConnu;
            $score = 1 - levenshtein($son, $sonConnu) / max(\strlen($son), \strlen($sonConnu));
            if ($score >= self::RESSEMBLANCE_MIN && (null === $meilleur || $score > $meilleur[0])) {
                $meilleur = [$score, $forme];
            }
        }
        if (null === $meilleur) {
            return null;
        }
        foreach ($motsConnus as $mot) {
            if (!str_contains(' '.self::normaliser($meilleur[1]).' ', ' '.$mot.' ')) {
                return null;
            }
        }
        $dernier = $fenetre[\count($fenetre) - 1];

        return [
            'debut' => $fenetre[0][1],
            'longueur' => $dernier[1] + \strlen($dernier[0]) - $fenetre[0][1],
            // « à Gwai » → « à Agoè » : le « à » absorbé par la transcription est remis.
            'remplacement' => ($estA ? $premier.' ' : '').$meilleur[1],
            'score' => $meilleur[0],
        ];
    }
}
