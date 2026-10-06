<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\DemandeVerification;
use App\Entity\Signalement;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeBien;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres de l'interface d'administration (tableau de bord et rapports).
 * Les agrégations par mois sont faites en SQL (MySQL).
 */
class StatistiquesAdmin
{
    private const MOIS_COURTS = ['Janv', 'Févr', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];

    private Connection $sql;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->sql = $em->getConnection();
    }

    /**
     * Les quatre cartes du tableau de bord, avec les nouveautés du mois.
     *
     * @return array<string, array{total: int, ceMois: int}>
     */
    public function indicateurs(): array
    {
        $debutMois = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->format('Y-m-d H:i:s');
        $parRole = fn (RoleUtilisateur $role) => [
            'total' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM utilisateur WHERE role = ?', [$role->value]),
            'ceMois' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM utilisateur WHERE role = ? AND date_inscription >= ?', [$role->value, $debutMois]),
        ];

        return [
            'clients' => $parRole(RoleUtilisateur::LOCATAIRE),
            'agents' => $parRole(RoleUtilisateur::AGENT),
            'proprietaires' => $parRole(RoleUtilisateur::PROPRIETAIRE),
            'biens' => [
                'total' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM annonce'),
                'ceMois' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM annonce WHERE date_publication >= ?', [$debutMois]),
            ],
            'aTraiter' => [
                'total' => $this->elementsATraiter(),
                'ceMois' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM signalement WHERE date_creation >= ?', [$debutMois])
                    + (int) $this->sql->fetchOne('SELECT COUNT(*) FROM demande_verification WHERE date_demande >= ?', [$debutMois]),
            ],
        ];
    }

    /** Annonces retirées du public faute de confirmation de leur annonceur. */
    public function annoncesAConfirmer(): int
    {
        return (int) $this->sql->fetchOne('SELECT COUNT(*) FROM annonce WHERE statut = ?', [StatutAnnonce::A_CONFIRMER->value]);
    }

    public function signalementsEnAttente(): int
    {
        return (int) $this->sql->fetchOne('SELECT COUNT(*) FROM signalement WHERE statut = ?', [Signalement::EN_ATTENTE]);
    }

    public function verificationsEnAttente(): int
    {
        return (int) $this->sql->fetchOne('SELECT COUNT(*) FROM demande_verification WHERE statut = ?', [DemandeVerification::EN_ATTENTE]);
    }

    public function elementsATraiter(): int
    {
        return $this->signalementsEnAttente() + $this->verificationsEnAttente();
    }

    /**
     * Annonces publiées par mois (location et vente), sur les $nombreMois derniers mois.
     *
     * @return array{mois: list<string>, location: list<int>, vente: list<int>}
     */
    public function evolutionAnnonces(int $nombreMois = 6): array
    {
        $mois = $this->derniersMois($nombreMois);
        $lignes = $this->sql->fetchAllAssociative(
            "SELECT DATE_FORMAT(date_publication, '%Y-%m') AS mois, type_transaction AS type, COUNT(*) AS n
             FROM annonce WHERE date_publication >= ? GROUP BY mois, type",
            [array_key_first($mois).'-01'],
        );
        $series = ['location' => array_fill_keys(array_keys($mois), 0), 'vente' => array_fill_keys(array_keys($mois), 0)];
        foreach ($lignes as $l) {
            if (isset($series[$l['type']][$l['mois']])) {
                $series[$l['type']][$l['mois']] = (int) $l['n'];
            }
        }

        return ['mois' => array_values($mois), 'location' => array_values($series['location']), 'vente' => array_values($series['vente'])];
    }

    /**
     * Inscriptions par mois et par rôle.
     *
     * @return array{mois: list<string>, series: array<string, list<int>>}
     */
    public function evolutionInscriptions(int $nombreMois = 12): array
    {
        $mois = $this->derniersMois($nombreMois);
        $lignes = $this->sql->fetchAllAssociative(
            "SELECT DATE_FORMAT(date_inscription, '%Y-%m') AS mois, role, COUNT(*) AS n
             FROM utilisateur WHERE date_inscription >= ? AND role <> 'admin' GROUP BY mois, role",
            [array_key_first($mois).'-01'],
        );
        $series = [];
        foreach ([RoleUtilisateur::LOCATAIRE, RoleUtilisateur::AGENT, RoleUtilisateur::PROPRIETAIRE] as $role) {
            $series[$role->value] = array_fill_keys(array_keys($mois), 0);
        }
        foreach ($lignes as $l) {
            if (isset($series[$l['role']][$l['mois']])) {
                $series[$l['role']][$l['mois']] = (int) $l['n'];
            }
        }

        return ['mois' => array_values($mois), 'series' => array_map('array_values', $series)];
    }

    /**
     * Répartition des annonces par type de bien, les plus nombreux d'abord.
     *
     * @return list<array{type: string, libelle: string, nombre: int, pourcentage: int}>
     */
    public function repartitionTypes(): array
    {
        $lignes = $this->sql->fetchAllKeyValue('SELECT type_bien, COUNT(*) FROM annonce GROUP BY type_bien');
        $total = array_sum($lignes) ?: 1;
        $resultat = [];
        foreach (TypeBien::cases() as $type) {
            $n = (int) ($lignes[$type->value] ?? 0);
            if ($n > 0) {
                $resultat[] = ['type' => $type->value, 'libelle' => $type->libelle(), 'nombre' => $n, 'pourcentage' => (int) round($n * 100 / $total)];
            }
        }
        usort($resultat, fn ($a, $b) => $b['nombre'] <=> $a['nombre']);

        return $resultat;
    }

    /** @return list<array{region: string, annonces: int, utilisateurs: int}> */
    public function parRegion(): array
    {
        $annonces = $this->sql->fetchAllKeyValue('SELECT localisation_region, COUNT(*) FROM annonce GROUP BY localisation_region');
        $utilisateurs = $this->sql->fetchAllKeyValue("SELECT region, COUNT(*) FROM utilisateur WHERE role <> 'admin' GROUP BY region");
        $resultat = [];
        foreach (\App\Enum\Region::cases() as $region) {
            $resultat[] = ['region' => $region->libelle(), 'annonces' => (int) ($annonces[$region->value] ?? 0), 'utilisateurs' => (int) ($utilisateurs[$region->value] ?? 0)];
        }

        return $resultat;
    }

    /** @return array<string, int|float|null> chiffres d'activité globaux (rapports) */
    public function activite(): array
    {
        return [
            'vues' => (int) $this->sql->fetchOne('SELECT COALESCE(SUM(nombre_vues), 0) FROM annonce'),
            'conversations' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM conversation'),
            'messages' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM message'),
            'favoris' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM favori'),
            'avis' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM avis'),
            'noteMoyenne' => ($n = $this->sql->fetchOne('SELECT AVG(note) FROM avis')) !== null ? round((float) $n, 1) : null,
            'alertes' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM alerte_recherche'),
            'agentsVerifies' => (int) $this->sql->fetchOne("SELECT COUNT(*) FROM utilisateur WHERE role IN ('agent', 'proprietaire') AND verifie = 1"),
            'comptesSuspendus' => (int) $this->sql->fetchOne('SELECT COUNT(*) FROM utilisateur WHERE est_actif = 0'),
        ];
    }

    /**
     * Agents et propriétaires classés par nombre de vues de leurs annonces.
     *
     * @return list<array<string, mixed>>
     */
    public function meilleursAnnonceurs(int $limite = 5): array
    {
        return $this->sql->fetchAllAssociative(
            "SELECT u.id, u.prenom, u.nom, u.identifiant, u.avatar, u.role, u.verifie,
                    COUNT(a.id) AS annonces, COALESCE(SUM(a.nombre_vues), 0) AS vues
             FROM utilisateur u LEFT JOIN annonce a ON a.publie_par_id = u.id
             WHERE u.role IN ('agent', 'proprietaire')
             GROUP BY u.id ORDER BY vues DESC, annonces DESC LIMIT ".(int) $limite,
        );
    }

    /** @return list<Annonce> */
    public function derniersBiens(int $limite = 4): array
    {
        return $this->em->getRepository(Annonce::class)->createQueryBuilder('a')
            ->addSelect('m', 'p')
            ->leftJoin('a.medias', 'm')
            ->join('a.publiePar', 'p')
            ->orderBy('a.datePublication', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limite * 10) // plusieurs lignes par annonce à cause des médias
            ->getQuery()
            ->getResult();
    }

    /**
     * Derniers événements de la plateforme, du plus récent au plus ancien.
     *
     * @return list<array{type: string, titre: string, detail: string, date: \DateTimeImmutable, lien: array{route: string, parametres: array<string, int>}|null}>
     */
    public function activiteRecente(int $limite = 6): array
    {
        $evenements = [];
        foreach ($this->sql->fetchAllAssociative("SELECT id, prenom, nom, role, date_inscription FROM utilisateur WHERE role <> 'admin' ORDER BY date_inscription DESC LIMIT ".$limite) as $u) {
            $role = RoleUtilisateur::from($u['role']);
            $evenements[] = [
                'type' => 'inscription',
                'titre' => 'Nouvelle inscription · '.mb_strtolower($role->libelle()),
                'detail' => trim($u['prenom'].' '.mb_strtoupper($u['nom'])),
                'date' => new \DateTimeImmutable($u['date_inscription']),
                'lien' => ['route' => 'admin_utilisateur', 'parametres' => ['id' => (int) $u['id']]],
            ];
        }
        foreach ($this->sql->fetchAllAssociative('SELECT id, titre, localisation_ville, date_publication FROM annonce ORDER BY date_publication DESC LIMIT '.$limite) as $a) {
            $evenements[] = [
                'type' => 'annonce',
                'titre' => 'Nouveau bien publié',
                'detail' => $a['titre'].' – '.$a['localisation_ville'],
                'date' => new \DateTimeImmutable($a['date_publication']),
                'lien' => ['route' => 'admin_bien', 'parametres' => ['id' => (int) $a['id']]],
            ];
        }
        foreach ($this->sql->fetchAllAssociative('SELECT s.id, s.motif, s.date_creation, a.titre FROM signalement s JOIN annonce a ON a.id = s.annonce_id ORDER BY s.date_creation DESC LIMIT '.$limite) as $s) {
            $evenements[] = [
                'type' => 'signalement',
                'titre' => 'Annonce signalée',
                'detail' => $s['titre'].' – '.\App\Enum\MotifSignalement::from($s['motif'])->libelle(),
                'date' => new \DateTimeImmutable($s['date_creation']),
                'lien' => ['route' => 'admin_signalements', 'parametres' => []],
            ];
        }
        foreach ($this->sql->fetchAllAssociative('SELECT d.id, d.date_demande, u.prenom, u.nom FROM demande_verification d JOIN utilisateur u ON u.id = d.utilisateur_id ORDER BY d.date_demande DESC LIMIT '.$limite) as $d) {
            $evenements[] = [
                'type' => 'verification',
                'titre' => 'Demande de vérification d\'identité',
                'detail' => trim($d['prenom'].' '.mb_strtoupper($d['nom'])),
                'date' => new \DateTimeImmutable($d['date_demande']),
                'lien' => ['route' => 'admin_verifications', 'parametres' => []],
            ];
        }
        foreach ($this->sql->fetchAllAssociative('SELECT v.note, v.date_creation, u.prenom, u.nom FROM avis v JOIN utilisateur u ON u.id = v.auteur_id ORDER BY v.date_creation DESC LIMIT '.$limite) as $v) {
            $evenements[] = [
                'type' => 'avis',
                'titre' => \sprintf('Nouvel avis · %d/5', $v['note']),
                'detail' => 'Par '.trim($v['prenom'].' '.mb_strtoupper($v['nom'])),
                'date' => new \DateTimeImmutable($v['date_creation']),
                'lien' => null,
            ];
        }
        usort($evenements, fn ($a, $b) => $b['date'] <=> $a['date']);

        return \array_slice($evenements, 0, $limite);
    }

    /** @return array<string, string> « 2026-04 » => « Avr » pour les derniers mois (le mois courant en dernier) */
    private function derniersMois(int $nombre): array
    {
        $mois = [];
        $debut = new \DateTimeImmutable('first day of this month');
        for ($i = $nombre - 1; $i >= 0; --$i) {
            $m = $debut->modify("-$i months");
            $mois[$m->format('Y-m')] = self::MOIS_COURTS[(int) $m->format('n') - 1];
        }

        return $mois;
    }
}
