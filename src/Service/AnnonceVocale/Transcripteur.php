<?php

namespace App\Service\AnnonceVocale;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transcription de la voix en texte par Whisper (open source), sur notre serveur :
 *   - whisper.cpp en mode serveur : WHISPER_URL=http://localhost:8080/inference
 *   - ou un serveur compatible OpenAI (faster-whisper-server…) : WHISPER_URL=http://…/v1/audio/transcriptions
 * Les téléphones enregistrent en M4A/AAC : l'audio est d'abord converti en WAV 16 kHz
 * par ffmpeg, format attendu par whisper.cpp.
 */
final class Transcripteur
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Filesystem $fichiers,
        #[Autowire('%env(WHISPER_URL)%')]
        private readonly string $url,
        #[Autowire('%env(int:WHISPER_DELAI_SECONDES)%')]
        private readonly int $delai,
        // Chemin complet de ffmpeg ; vide : recherché dans le PATH du serveur PHP.
        #[Autowire('%env(FFMPEG_CHEMIN)%')]
        private readonly string $cheminFfmpeg,
        private readonly LoggerInterface $journal,
    ) {
    }

    public function estConfigure(): bool
    {
        return '' !== trim($this->url);
    }

    /** @throws TranscriptionImpossible */
    public function transcrire(UploadedFile $audio): string
    {
        if (!$this->estConfigure()) {
            throw new TranscriptionImpossible('La transcription vocale n\'est pas encore installée sur le serveur. Écrivez ou dictez votre annonce au clavier.');
        }

        $wav = $this->versWav($audio);
        try {
            $formulaire = new FormDataPart([
                'file' => DataPart::fromPath($wav ?? $audio->getPathname(), 'annonce.'.(null !== $wav ? 'wav' : ($audio->guessExtension() ?? 'm4a'))),
                'language' => 'fr',
                'response_format' => 'json',
                'model' => 'whisper-1', // ignoré par whisper.cpp, requis par les serveurs compatibles OpenAI
                'temperature' => '0',
                // Orthographe des lieux : sans cet indice, « Agbalépédo » devient « H. Vallée Pédot ».
                'prompt' => LieuxTogo::indiceTranscription(),
            ]);
            $reponse = $this->http->request('POST', $this->url, [
                'headers' => $formulaire->getPreparedHeaders()->toArray(),
                'body' => $formulaire->bodyToIterable(),
                'timeout' => $this->delai,
                'max_duration' => $this->delai,
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->journal->error('Transcription vocale impossible : {erreur}', ['erreur' => $e->getMessage(), 'audio_converti' => null !== $wav]);
            throw new TranscriptionImpossible(self::messageErreur($e), previous: $e);
        } finally {
            if (null !== $wav) {
                $this->fichiers->remove($wav);
            }
        }

        // whisper.cpp coupe ses lignes au milieu des mots (« compt|eur ») : on recolle.
        $texte = trim(str_replace(["\r", "\n"], '', (string) ($reponse['text'] ?? '')));
        if ('' === $texte) {
            throw new TranscriptionImpossible('Aucune parole reconnue. Parlez près du téléphone, dans un endroit calme, puis réessayez.');
        }

        return $texte;
    }

    /** Message pour l'agent, selon la cause : Whisper arrêté, trop lent, ou audio refusé. */
    private static function messageErreur(ExceptionInterface $e): string
    {
        if ($e instanceof HttpExceptionInterface) {
            return 'L\'enregistrement n\'a pas pu être lu. Réenregistrez votre annonce, ou écrivez-la.';
        }
        if ($e instanceof TransportExceptionInterface && preg_match('/connect/i', $e->getMessage())) {
            return 'La transcription vocale est arrêtée sur le serveur. Réessayez plus tard, ou écrivez votre annonce.';
        }
        if ($e instanceof TransportExceptionInterface && preg_match('/time|duration/i', $e->getMessage())) {
            return 'La transcription a pris trop de temps. Faites un enregistrement plus court, ou écrivez votre annonce.';
        }

        return 'Le service de transcription ne répond pas. Réessayez, ou écrivez votre annonce.';
    }

    /** Conversion en WAV mono 16 kHz si ffmpeg est installé ; sinon l'audio est envoyé tel quel. */
    private function versWav(UploadedFile $audio): ?string
    {
        $ffmpeg = '' !== trim($this->cheminFfmpeg) ? $this->cheminFfmpeg : (new ExecutableFinder())->find('ffmpeg');
        if (null === $ffmpeg || !is_file($ffmpeg)) {
            // whisper.cpp ne sait pas lire le M4A des téléphones : la transcription échouera.
            $this->journal->warning('ffmpeg introuvable : audio envoyé à Whisper sans conversion. Renseignez FFMPEG_CHEMIN.');

            return null;
        }
        $sortie = sys_get_temp_dir().'/logetogo-vocal-'.bin2hex(random_bytes(6)).'.wav';
        $conversion = new Process([$ffmpeg, '-y', '-loglevel', 'error', '-i', $audio->getPathname(), '-ar', '16000', '-ac', '1', $sortie]);
        $conversion->setTimeout(60);
        $conversion->run();

        return $conversion->isSuccessful() && is_file($sortie) ? $sortie : null;
    }
}
