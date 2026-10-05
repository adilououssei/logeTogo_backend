<?php

namespace App\Service\AnnonceVocale;

use App\Enum\TypeBien;
use App\Enum\TypeTransaction;

/**
 * Extraction « par règles » (sans IA, gratuite) des informations d'une annonce dictée.
 * Fonctionne bien quand l'agent cite les éléments attendus (type, lieu, prix, avance…).
 */
final class ExtracteurRegles
{
    /** Motif => type de bien, du plus précis au plus général (« villa de 4 chambres » est une villa). */
    private const TYPES = [
        '/\b(terrain|parcelle|lot|lots|demi[- ]lot)\b/u' => TypeBien::TERRAIN,
        '/\bvilla\b/u' => TypeBien::VILLA,
        '/\b(appartement|appart)\b/u' => TypeBien::APPARTEMENT,
        '/\bstudio\b/u' => TypeBien::STUDIO,
        '/\b(bureau|bureaux|magasin|boutique|local commercial|entrepot)\b/u' => TypeBien::BUREAU,
        '/\b(maison|duplex|immeuble|bungalow)\b/u' => TypeBien::MAISON,
        '/\bchambres?\b/u' => TypeBien::CHAMBRE,
    ];

    /** Motif (texte sans accents) => équipement affiché dans l'application. */
    private const EQUIPEMENTS = [
        '/\b(douche|salle de bain|salle d eau|toilette)s? (interne|privee|individuelle|a l interieur)/' => 'Salle de bain privée',
        '/\bcompteurs?(?: \w+){0,3} (personnel|individuel|propre|a part|separe)/' => 'Compteur personnel',
        '/\bforage\b/' => 'Forage',
        '/\b(eau courante|eau de la tde|tde|robinet)\b/' => 'Eau courante',
        '/\b(electricite|courant electrique|ceet)\b/' => 'Électricité',
        '/\b(climatisation|climatise|climatisee|clim|climatiseur)\b/' => 'Climatisation',
        '/\b(parking|garage|stationnement)\b/' => 'Parking',
        '/\b(gardien|gardiennage)\b/' => 'Gardien',
        '/\b(securite|vigile|securise)\b/' => 'Sécurité 24/7',
        '/\b(wifi|wi fi|internet|fibre)\b/' => 'WiFi',
        '/\bcuisine (equipee|americaine|moderne)/' => 'Cuisine équipée',
        '/\bcuisine (interne|a l interieur)/' => 'Cuisine interne',
        '/\bterrasse\b/' => 'Terrasse',
        '/\bjardin\b/' => 'Jardin',
        '/\bpiscine\b/' => 'Piscine',
        '/\bascenseur\b/' => 'Ascenseur',
        '/\b(groupe electrogene|groupe)\b/' => 'Groupe électrogène',
        '/\btitre foncier\b/' => 'Titre foncier',
        '/\bborne\b/' => 'Borné',
        '/\b(cloture|cloturee|cloturé|mur de cloture)\b/' => 'Clôturé',
        '/\bcarrel(e|ee|age)\b/' => 'Carrelé',
        '/\bplafonn(e|ee)\b/' => 'Plafonné',
        '/\bmeubl(e|ee)\b/' => 'Meublé',
        '/\bbalcon\b/' => 'Balcon',
        '/\b(placard|placards|dressing)\b/' => 'Placards',
        '/\b(portail|portail electrique)\b/' => 'Portail',
        '/\b(pave|pavee|paves|pavees)\b/' => 'Cour pavée',
    ];

    /** Formules de politesse ou d'hésitation retirées de la description. */
    private const MOTS_PARASITES = '/\b(bonjour|bonsoir|salut|euh+|heu+|hum+|donc voila|voila|merci)\b[\s,.!]*/iu';

    /**
     * @param list<array{nom: string, ville: string, variantes: list<string>, prioritaire: bool}> $quartiersAjoutes quartiers ajoutés par les agents
     *
     * @return array<string, mixed> champs de l'annonce trouvés dans le texte (null si absent)
     */
    public function extraire(string $texte, array $quartiersAjoutes = []): array
    {
        $chiffres = NombresEnLettres::convertir($texte);
        $bas = mb_strtolower($chiffres);
        $plat = LieuxTogo::normaliser($chiffres); // sans accents ni ponctuation

        $typeBien = $this->typeBien($bas);
        $typeTransaction = $this->typeTransaction($plat);
        // Au Togo : « 4 mois devant » = avance, « 2 mois de garantie » = caution.
        $avance = $this->mois($plat, '(?:avance|devant)');
        $caution = $this->mois($plat, '(?:caution|garantie)');
        $commission = $this->commission($plat);
        $prix = $this->prix($plat, array_filter([$commission]), $typeTransaction);
        $lieu = LieuxTogo::trouver($texte, $quartiersAjoutes);
        $chambreSalon = (bool) preg_match('/\bchambre[\s-]+salon\b/u', $bas);

        $champs = [
            'typeBien' => $typeBien,
            'typeTransaction' => $typeTransaction,
            'prix' => $prix,
            'avanceMois' => $avance,
            'cautionMois' => $caution,
            'commission' => $commission,
            'chambres' => $this->nombreAvant($plat, 'chambres?') ?? ($chambreSalon || TypeBien::CHAMBRE === $typeBien ? 1 : null),
            'sallesDeBain' => $this->nombreAvant($plat, '(?:salles? de bains?|salles? d eau|douches?|toilettes?)')
                ?? (preg_match('/\b(douche|salle de bain|toilette)s? (interne|privee)/', $plat) ? 1 : null),
            'superficie' => $this->superficie($plat),
            'quartier' => $lieu['quartier'],
            'ville' => $lieu['ville'],
            'region' => $lieu['region'],
            'adresse' => $this->adresse($chiffres),
            'equipements' => $this->equipements($plat),
        ];
        $champs['titre'] = $this->titre($champs, $chambreSalon);
        $champs['description'] = $this->description($texte);

        return $champs;
    }

    private function typeBien(string $bas): ?TypeBien
    {
        $plat = LieuxTogo::normaliser($bas);
        foreach (self::TYPES as $motif => $type) {
            if (preg_match($motif, $plat)) {
                return $type;
            }
        }

        return null;
    }

    private function typeTransaction(string $plat): ?TypeTransaction
    {
        $vente = preg_match('/\b(a vendre|vente|vends|je vends|a ceder|cession|prix de vente)\b/', $plat);
        $location = preg_match('/\b(a louer|location|loue|loyer|par mois|le mois|mensuel|avance|caution|garantie|mois devant)\b/', $plat);

        return match (true) {
            $vente && !$location => TypeTransaction::VENTE,
            (bool) $location => TypeTransaction::LOCATION,
            default => null,
        };
    }

    /** « 6 mois d'avance », « avance de 6 mois », « caution 3 mois ». */
    private function mois(string $plat, string $mot): ?int
    {
        foreach ([
            '/\b(\d{1,2}) mois (?:d |de )?'.$mot.'\b/',
            '/\b'.$mot.' (?:de |est de |c est |fait )?(\d{1,2}) mois\b/',
            '/\b'.$mot.' (?:de |est de |c est )?(\d{1,2})\b(?! ?\d)/',
        ] as $motif) {
            if (preg_match($motif, $plat, $m) && (int) $m[1] <= 24) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function commission(string $plat): ?int
    {
        foreach ([
            '/\b(?:commission|demarcheur|frais de visite|frais d agence)\b\D{0,30}?(\d{3,})/',
            '/\b(\d{3,})\D{0,15}(?:de |pour la |la )?(?:commission|demarcheur)\b/',
        ] as $motif) {
            if (preg_match($motif, $plat, $m)) {
                return (int) $m[1];
            }
        }
        if (preg_match('/\b(?:commission|demarcheur)\b\D{0,30}?(\d{1,2}) mois\b/', $plat)) {
            return null; // « commission d'un mois » : dépend du loyer, laissé à l'agent.
        }

        return null;
    }

    /** @param list<int> $dejaUtilises montants déjà attribués (commission) */
    private function prix(string $plat, array $dejaUtilises, ?TypeTransaction $transaction): ?int
    {
        foreach ([
            '/\b(\d{4,})\s*(?:f|fr|francs?|fcfa|cfa)?\s*(?:par mois|le mois|chaque mois|mensuel)/',
            '/\b(?:loyer|prix|coute|cout|vendu a|vendue a|a vendre a|a louer a)\s*(?:de |est de |c est |a |fait )?(\d{4,})/',
            '/\bc est (\d{4,})/',
        ] as $motif) {
            if (preg_match($motif, $plat, $m)) {
                return (int) $m[1];
            }
        }
        // Sinon, le plus grand montant cité qui n'est pas la commission.
        preg_match_all('/\b(\d{4,})\b/', $plat, $m);
        $montants = array_values(array_diff(array_map('intval', $m[1]), $dejaUtilises));
        $montants = array_filter($montants, fn (int $n) => $n >= 5000 && !(TypeTransaction::LOCATION === $transaction && $n > 5_000_000));

        return [] === $montants ? null : max($montants);
    }

    private function nombreAvant(string $plat, string $mot): ?int
    {
        return preg_match('/\b(\d{1,2}) '.$mot.'\b/', $plat, $m) ? (int) $m[1] : null;
    }

    private function superficie(string $plat): ?int
    {
        if (preg_match('/\b(\d{2,6}) ?(?:m2|m 2|metres? carres?|metre carre|mc)\b/', $plat, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** « non loin du marché », « derrière l'église », « à côté de la pharmacie ». */
    private function adresse(string $texte): ?string
    {
        if (preg_match('/\b(pas loin|non loin|pr[eè]s|derri[eè]re|devant|[aà] c[oô]t[eé]|en face|juste apr[eè]s|apr[eè]s|vers|au niveau|[aà] proximit[eé])\s+(du|de la|de l[\'’]|des|de|d[\'’]|la|le|l[\'’])\s*([^,.;!?]{3,60})/iu', $texte, $m)) {
            $lien = str_replace('’', '\'', $m[2]);

            return trim(mb_convert_case(mb_substr($m[1], 0, 1), \MB_CASE_UPPER).mb_substr($m[1], 1).' '.$lien.(str_ends_with($lien, '\'') ? '' : ' ').trim($m[3]));
        }

        return null;
    }

    /** @return list<string> */
    private function equipements(string $plat): array
    {
        $trouves = [];
        foreach (self::EQUIPEMENTS as $motif => $libelle) {
            if (preg_match($motif, $plat)) {
                $trouves[] = $libelle;
            }
        }

        return array_values(array_unique($trouves));
    }

    /** @param array<string, mixed> $champs */
    private function titre(array $champs, bool $chambreSalon): string
    {
        $type = $champs['typeBien'];
        $nom = match (true) {
            $chambreSalon => 'Chambre salon',
            TypeBien::CHAMBRE === $type && ($champs['chambres'] ?? 1) > 1 => $champs['chambres'].' chambres',
            $type instanceof TypeBien && \in_array($type, [TypeBien::VILLA, TypeBien::MAISON, TypeBien::APPARTEMENT], true) && ($champs['chambres'] ?? 0) > 0
                => \sprintf('%s %d chambre%s', $type->libelle(), $champs['chambres'], $champs['chambres'] > 1 ? 's' : ''),
            $type instanceof TypeBien => $type->libelle(),
            default => 'Bien',
        };
        $action = match ($champs['typeTransaction']) {
            TypeTransaction::VENTE => ' à vendre',
            TypeTransaction::LOCATION => ' à louer',
            default => '',
        };
        $lieu = $champs['quartier'] ?? $champs['ville'];

        return mb_substr($nom.$action.(null !== $lieu ? ' à '.$lieu : ''), 0, 150);
    }

    private function description(string $texte): string
    {
        $propre = trim((string) preg_replace([self::MOTS_PARASITES, '/\s{2,}/u'], ['', ' '], $texte));
        $propre = ltrim($propre, ' ,.;');

        return mb_substr(mb_convert_case(mb_substr($propre, 0, 1), \MB_CASE_UPPER).mb_substr($propre, 1), 0, 5000);
    }
}
