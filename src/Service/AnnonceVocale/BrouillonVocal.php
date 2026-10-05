<?php

namespace App\Service\AnnonceVocale;

use App\Entity\Utilisateur;
use App\Enum\TypeTransaction;
use App\Service\ReferentielQuartiers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Annonce dictée → brouillon du formulaire de publication (jamais publié directement :
 * l'agent relit, ajoute ses photos puis publie).
 *
 * Moteur choisi par ANNONCE_VOCALE_IA : « ollama » (règles + IA open source) ou « aucune »
 * (règles seules). En cas de panne de l'IA, les règles prennent le relais.
 */
final class BrouillonVocal
{
    /** En deçà, l'IA n'a pas le temps de répondre : on ne l'appelle pas. */
    private const DELAI_IA_MIN_SECONDES = 5;

    /** Champs complétés par l'IA quand elle a compris (les règles gardent le lieu et le reste). */
    private const CHAMPS_IA = ['typeBien', 'typeTransaction', 'prix', 'avanceMois', 'cautionMois', 'commission', 'chambres', 'sallesDeBain', 'superficie'];

    public function __construct(
        private readonly Transcripteur $transcripteur,
        private readonly ExtracteurRegles $regles,
        private readonly ExtracteurIa $ia,
        private readonly ReferentielQuartiers $quartiers,
        #[Autowire(service: 'limiter.annonce_vocale')]
        private readonly RateLimiterFactoryInterface $limite,
        #[Autowire('%env(ANNONCE_VOCALE_IA)%')]
        private readonly string $moteur,
        // Temps de réponse visé pour l'agent (transcription + IA) ; au-delà, les règles suffisent.
        #[Autowire('%env(int:ANNONCE_VOCALE_DELAI_SECONDES)%')]
        private readonly int $delaiTotal,
    ) {
    }

    /**
     * @return array{transcription: string, moteur: string, annonce: array<string, mixed>, champsManquants: list<string>}
     *
     * @throws TranscriptionImpossible
     */
    public function preparer(Utilisateur $agent, ?UploadedFile $audio, ?string $texte): array
    {
        if (!$this->limite->create('vocal-'.$agent->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Vous avez atteint la limite d\'annonces vocales pour aujourd\'hui. Utilisez le formulaire classique.');
        }

        $debut = microtime(true);
        $ajoutes = $this->quartiers->ajoutes();
        $transcription = null !== $audio ? $this->transcripteur->transcrire($audio) : trim((string) $texte);
        // Noms de quartiers mal transcrits, corrigés « au son » (« à Gwai » → « à Agoè »).
        $transcription = LieuxTogo::corrigerNoms($transcription, $ajoutes);
        $champs = $this->regles->extraire($transcription, $ajoutes);

        // L'IA (lente sur processeur) ne sert que si les règles n'ont pas tout trouvé,
        // et seulement dans le temps qui reste.
        $moteur = 'regles';
        $resteSecondes = $this->delaiTotal - (microtime(true) - $debut);
        if ('ollama' === $this->moteur && [] !== $this->champsManquants($champs) && $resteSecondes >= self::DELAI_IA_MIN_SECONDES
            && null !== ($ia = $this->ia->extraire($transcription, $resteSecondes))) {
            foreach (self::CHAMPS_IA as $cle) {
                // Un 0 de l'IA (« non dit ») n'efface pas un nombre trouvé par les règles.
                if (null !== $ia[$cle] && !(0 === $ia[$cle] && null !== $champs[$cle])) {
                    $champs[$cle] = $ia[$cle];
                }
            }
            $moteur = 'regles+ia';
        }

        return [
            'transcription' => $transcription,
            'moteur' => $moteur,
            'annonce' => $this->versJson($champs),
            'champsManquants' => $this->champsManquants($champs),
        ];
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>
     */
    private function versJson(array $c): array
    {
        $location = TypeTransaction::LOCATION === $c['typeTransaction'];

        return [
            'titre' => $c['titre'],
            'description' => $c['description'],
            'typeBien' => $c['typeBien']?->value,
            'typeTransaction' => $c['typeTransaction']?->value,
            'prix' => $c['prix'],
            'avanceMois' => $location ? $c['avanceMois'] : null,
            'cautionMois' => $location ? $c['cautionMois'] : null,
            'commission' => $location ? $c['commission'] : null,
            'chambres' => $c['chambres'],
            'sallesDeBain' => $c['sallesDeBain'],
            'superficie' => $c['superficie'],
            'equipements' => $c['equipements'],
            'region' => $c['region']?->value,
            'ville' => $c['ville'],
            'quartier' => $c['quartier'],
            'adresse' => $c['adresse'],
        ];
    }

    /**
     * Informations nécessaires à la publication qui n'ont pas été comprises : signalées à l'agent.
     *
     * @param array<string, mixed> $c
     *
     * @return list<string>
     */
    private function champsManquants(array $c): array
    {
        $manquants = [];
        foreach (['typeBien', 'typeTransaction', 'prix', 'region', 'ville', 'quartier'] as $cle) {
            if (null === $c[$cle] || '' === $c[$cle]) {
                $manquants[] = $cle;
            }
        }
        if (TypeTransaction::LOCATION === $c['typeTransaction'] && null === $c['avanceMois']) {
            $manquants[] = 'avanceMois';
        }

        return $manquants;
    }
}
