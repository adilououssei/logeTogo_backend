<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\AppareilRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie des notifications push aux téléphones des utilisateurs via le service Expo
 * (https://docs.expo.dev/push-notifications/sending-notifications/).
 * Un échec d'envoi ne bloque jamais l'action qui l'a déclenché (message, publication…).
 */
class EnvoiPush
{
    /** Expo accepte au plus 100 notifications par requête. */
    private const TAILLE_LOT = 100;

    public function __construct(
        private readonly HttpClientInterface $expoClient,
        private readonly AppareilRepository $appareils,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Utilisateur>    $destinataires
     * @param array<string, mixed> $donnees        transmises à l'application (ce qu'il faut ouvrir au toucher)
     * @param string               $canal          canal Android créé par l'application : « messages » (bandeau
     *                                             en haut de l'écran, comme WhatsApp), « annonces » ou « defaut »
     */
    public function envoyer(array $destinataires, string $titre, string $corps, array $donnees = [], string $canal = 'defaut'): void
    {
        $jetons = array_map(fn ($a) => $a->getJetonPush(), $this->appareils->trouverPourUtilisateurs($destinataires));
        if ([] === $jetons) {
            return;
        }

        $invalides = [];
        foreach (array_chunk($jetons, self::TAILLE_LOT) as $lot) {
            $messages = array_map(fn (string $jeton) => [
                'to' => $jeton,
                'title' => $titre,
                'body' => mb_strimwidth($corps, 0, 180, '…'),
                'data' => $donnees,
                'sound' => 'default',
                'channelId' => $canal,
                // Livrée tout de suite, même téléphone en veille.
                'priority' => 'high',
            ], $lot);

            try {
                $reponse = $this->expoClient->request('POST', '/--/api/v2/push/send', ['json' => $messages])->toArray();
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Envoi push impossible : {erreur}', ['erreur' => $e->getMessage()]);
                continue;
            }

            // Les résultats arrivent dans l'ordre des messages envoyés.
            foreach ($reponse['data'] ?? [] as $i => $resultat) {
                if ('DeviceNotRegistered' === ($resultat['details']['error'] ?? null)) {
                    $invalides[] = $lot[$i];
                }
            }
        }

        // Application désinstallée ou notifications désactivées : on oublie ces téléphones.
        $this->appareils->supprimerJetons($invalides);
    }
}
