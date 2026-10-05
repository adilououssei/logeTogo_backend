<?php

namespace App\Service\AnnonceVocale;

use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extraction par une IA open source servie par Ollama (ex. qwen3:4b), sur notre propre serveur.
 * L'IA ne donne que ce qu'elle comprend le mieux (type, transaction, montants, pièces) ;
 * le lieu, les équipements, le titre et la description viennent des règles.
 */
final class ExtracteurIa
{
    private const CONSIGNE = <<<'TXT'
        Tu lis la transcription d'une annonce immobilière dictée par un agent au Togo.
        Extrais uniquement ce qui est dit. Montants en francs CFA, nombres entiers (« vingt-cinq mille » = 25000).
        « chambre salon » = une chambre avec salon (typeBien chambre, chambres 1).
        prix = loyer mensuel pour une location, prix total pour une vente. avanceMois et cautionMois en nombre de mois.
        commission = frais du démarcheur ou de l'agent, en francs. Mets null quand l'information n'est pas donnée : n'invente rien.
        TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'typeBien' => ['type' => ['string', 'null'], 'enum' => ['chambre', 'studio', 'appartement', 'maison', 'villa', 'bureau', 'terrain', null]],
            'typeTransaction' => ['type' => ['string', 'null'], 'enum' => ['location', 'vente', null]],
            'prix' => ['type' => ['integer', 'null']],
            'avanceMois' => ['type' => ['integer', 'null']],
            'cautionMois' => ['type' => ['integer', 'null']],
            'commission' => ['type' => ['integer', 'null']],
            'chambres' => ['type' => ['integer', 'null']],
            'sallesDeBain' => ['type' => ['integer', 'null']],
            'superficie' => ['type' => ['integer', 'null']],
        ],
        'required' => ['typeBien', 'typeTransaction', 'prix', 'avanceMois', 'cautionMois', 'commission', 'chambres', 'sallesDeBain', 'superficie'],
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(OLLAMA_URL)%')]
        private readonly string $url,
        #[Autowire('%env(OLLAMA_MODELE)%')]
        private readonly string $modele,
        #[Autowire('%env(int:OLLAMA_DELAI_SECONDES)%')]
        private readonly int $delai,
    ) {
    }

    /**
     * @return array<string, mixed>|null champs compris par l'IA ; null si Ollama ne répond pas
     *                                    (arrêté, trop lent, réponse illisible) : les règles suffisent alors
     */
    public function extraire(string $texte, ?float $delaiMax = null): ?array
    {
        $delai = min($this->delai, $delaiMax ?? $this->delai);
        try {
            $reponse = $this->http->request('POST', rtrim($this->url, '/').'/api/chat', [
                'json' => [
                    'model' => $this->modele,
                    'stream' => false,
                    'think' => false,
                    'keep_alive' => '5m', // gardé 5 min (vocaux enchaînés), puis libéré : il occupe ~3 Go de mémoire
                    'options' => ['temperature' => 0, 'num_predict' => 200],
                    'format' => self::SCHEMA,
                    'messages' => [
                        ['role' => 'system', 'content' => self::CONSIGNE],
                        ['role' => 'user', 'content' => $texte],
                    ],
                ],
                'timeout' => $delai,
                'max_duration' => $delai,
            ])->toArray();
            $donnees = json_decode((string) ($reponse['message']['content'] ?? ''), true, 4, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            $this->logger->warning('Annonce vocale : IA indisponible ({erreur}), règles seules.', ['erreur' => $e->getMessage()]);

            return null;
        }

        return \is_array($donnees) ? $this->nettoyer($donnees) : null;
    }

    /**
     * Valeurs hors limites écartées (un modèle peut se tromper d'unité).
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function nettoyer(array $d): array
    {
        $entier = fn (string $cle, int $min, int $max) => isset($d[$cle]) && \is_int($d[$cle]) && $d[$cle] >= $min && $d[$cle] <= $max ? $d[$cle] : null;

        return [
            'typeBien' => \is_string($d['typeBien'] ?? null) ? TypeBien::tryFrom($d['typeBien']) : null,
            'typeTransaction' => \is_string($d['typeTransaction'] ?? null) ? TypeTransaction::tryFrom($d['typeTransaction']) : null,
            'prix' => $entier('prix', 1000, 10_000_000_000),
            'avanceMois' => $entier('avanceMois', 0, 24),
            'cautionMois' => $entier('cautionMois', 0, 24),
            'commission' => $entier('commission', 0, 100_000_000),
            'chambres' => $entier('chambres', 0, 50),
            'sallesDeBain' => $entier('sallesDeBain', 0, 50),
            'superficie' => $entier('superficie', 1, 10_000_000),
        ];
    }
}
