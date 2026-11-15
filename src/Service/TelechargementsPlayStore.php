<?php

namespace App\Service;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\JwtFacade;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Nombre exact de téléchargements sur Google Play.
 *
 * Google n'a pas d'API « nombre de téléchargements ». La seule source exacte est l'export mensuel
 * « Statistiques > Installations » que la Play Console dépose dans un bucket Cloud Storage
 * (pubsite_prod_rev_…). On lit ces fichiers CSV avec un compte de service en lecture seule.
 * Le résultat est gardé en cache (les fichiers ne changent qu'une fois par jour).
 */
class TelechargementsPlayStore
{
    private const URL_JETON = 'https://oauth2.googleapis.com/token';
    private const URL_STOCKAGE = 'https://storage.googleapis.com/storage/v1/b';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        /** Chemin du fichier JSON du compte de service (hors de Git). Vide = fonction désactivée. */
        #[Autowire(env: 'GOOGLE_PLAY_CLE_JSON')]
        private readonly string $cheminCle,
        /** Nom du bucket Cloud Storage de la Play Console (Télécharger les rapports > Statistiques). */
        #[Autowire(env: 'GOOGLE_PLAY_BUCKET')]
        private readonly string $bucket,
        #[Autowire(env: 'GOOGLE_PLAY_PACKAGE')]
        private readonly string $paquet,
    ) {
    }

    public function configure(): bool
    {
        return '' !== $this->cheminCle && '' !== $this->bucket && '' !== $this->paquet && is_file($this->cheminCle);
    }

    /**
     * @return array{total: int, actifs: ?int, mois: array<string, int>, jusquau: ?string, erreur: ?string}
     */
    public function statistiques(bool $forcer = false): array
    {
        if (!$this->configure()) {
            return ['total' => 0, 'actifs' => null, 'mois' => [], 'jusquau' => null, 'erreur' => 'non_configure'];
        }
        if ($forcer) {
            $this->cache->delete('play_telechargements');
        }

        try {
            return $this->cache->get('play_telechargements', function (ItemInterface $item): array {
                $item->expiresAfter(6 * 3600);

                return $this->calculer();
            });
        } catch (\Throwable $e) {
            return ['total' => 0, 'actifs' => null, 'mois' => [], 'jusquau' => null, 'erreur' => $e->getMessage()];
        }
    }

    /** @return array{total: int, actifs: ?int, mois: array<string, int>, jusquau: ?string, erreur: ?string} */
    private function calculer(): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->jetonAcces()]];
        $base = sprintf('%s/%s/o', self::URL_STOCKAGE, rawurlencode($this->bucket));

        $liste = $this->httpClient->request('GET', $base, $options + [
            'query' => ['prefix' => sprintf('stats/installs/installs_%s_', $this->paquet), 'maxResults' => 1000],
        ])->toArray();

        $noms = [];
        foreach ($liste['items'] ?? [] as $objet) {
            if (str_ends_with($objet['name'], '_overview.csv')) {
                $noms[] = $objet['name'];
            }
        }
        sort($noms);

        $mois = [];
        $actifs = null;
        $jusquau = null;
        foreach ($noms as $nom) {
            $csv = $this->httpClient->request('GET', $base.'/'.rawurlencode($nom), $options + [
                'query' => ['alt' => 'media'],
            ])->getContent();
            [$nombre, $dernierActifs, $derniereDate] = self::lireCsv($csv);
            $mois[$this->moisDuFichier($nom)] = $nombre;
            if (null !== $derniereDate) {
                $actifs = $dernierActifs;
                $jusquau = $derniereDate;
            }
        }

        return ['total' => array_sum($mois), 'actifs' => $actifs, 'mois' => $mois, 'jusquau' => $jusquau, 'erreur' => null];
    }

    /**
     * Lit un CSV Google Play (UTF-16, parfois UTF-8) : somme des « Daily User Installs »,
     * dernier « Active Device Installs » et dernière date.
     *
     * @return array{0: int, 1: ?int, 2: ?string}
     */
    public static function lireCsv(string $contenu): array
    {
        if (str_starts_with($contenu, "\xFF\xFE")) {
            $contenu = mb_convert_encoding(substr($contenu, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contenu, "\xFE\xFF")) {
            $contenu = mb_convert_encoding(substr($contenu, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }

        $lignes = array_values(array_filter(preg_split('/\r\n|\n|\r/', $contenu), static fn (string $l): bool => '' !== trim($l)));
        if (\count($lignes) < 2) {
            return [0, null, null];
        }
        $entetes = str_getcsv(array_shift($lignes), ',', '"', '');
        $colInstall = array_search('Daily User Installs', $entetes, true);
        $colActifs = array_search('Active Device Installs', $entetes, true);
        $colDate = array_search('Date', $entetes, true);
        if (false === $colInstall) {
            return [0, null, null];
        }

        $total = 0;
        $actifs = null;
        $date = null;
        foreach ($lignes as $ligne) {
            $c = str_getcsv($ligne, ',', '"', '');
            $total += (int) ($c[$colInstall] ?? 0);
            if (false !== $colActifs && isset($c[$colActifs]) && '' !== $c[$colActifs]) {
                $actifs = (int) $c[$colActifs];
            }
            if (false !== $colDate) {
                $date = $c[$colDate] ?? $date;
            }
        }

        return [$total, $actifs, $date];
    }

    private function moisDuFichier(string $nom): string
    {
        return preg_match('/_(\d{4})(\d{2})_overview\.csv$/', $nom, $m) ? $m[1].'-'.$m[2] : $nom;
    }

    private function jetonAcces(): string
    {
        $cle = json_decode((string) file_get_contents($this->cheminCle), true, flags: JSON_THROW_ON_ERROR);
        $jwt = (new JwtFacade())->issue(
            new Sha256(),
            InMemory::plainText($cle['private_key']),
            static fn (Builder $b, \DateTimeImmutable $maintenant): Builder => $b
                ->issuedBy($cle['client_email'])
                ->permittedFor(self::URL_JETON)
                ->withClaim('scope', 'https://www.googleapis.com/auth/devstorage.read_only')
                ->expiresAt($maintenant->modify('+30 minutes')),
        );

        return $this->httpClient->request('POST', self::URL_JETON, [
            'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt->toString()],
        ])->toArray()['access_token'];
    }
}
