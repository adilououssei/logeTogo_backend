<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\Voter\AnnonceVoter;
use App\Service\AnnonceVocale\BrouillonVocal;
use App\Service\AnnonceVocale\TranscriptionImpossible;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annonce vocale : l'agent dicte son annonce, le serveur renvoie le formulaire pré-rempli.
 * Rien n'est publié ni conservé : l'enregistrement est supprimé après traitement.
 */
#[Route('/api/annonces/brouillon-vocal', name: 'api_brouillon_vocal', format: 'json')]
#[IsGranted(AnnonceVoter::PUBLIER, message: 'Réservé aux agents et propriétaires.')]
class BrouillonVocalController extends AbstractController
{
    private const TYPES_AUDIO = ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/ogg', 'audio/3gpp', 'video/mp4', 'video/3gpp', 'video/webm', 'application/octet-stream'];

    /**
     * Multipart « audio » (enregistrement, 3 minutes / 20 Mo au plus), ou « texte » (dictée au clavier).
     * → {"transcription", "moteur", "annonce": {...}, "champsManquants": [...]}
     */
    #[Route('', methods: ['POST'])]
    public function preparer(Request $requete, #[CurrentUser] Utilisateur $agent, BrouillonVocal $brouillon): JsonResponse
    {
        $audio = $requete->files->get('audio');
        $texte = trim((string) ($requete->request->get('texte') ?? $requete->getPayload()->get('texte') ?? ''));

        if ($audio instanceof UploadedFile) {
            if (!$audio->isValid() || $audio->getSize() > 20 * 1024 * 1024) {
                return $this->refus('audio', 'Enregistrement trop long ou illisible (20 Mo au plus).');
            }
            if (!\in_array($audio->getMimeType(), self::TYPES_AUDIO, true)) {
                return $this->refus('audio', 'Format audio non accepté.');
            }
        } elseif (mb_strlen($texte) < 15) {
            return $this->refus('texte', 'Décrivez le bien en quelques phrases (type, lieu, prix…).');
        } elseif (mb_strlen($texte) > 5000) {
            return $this->refus('texte', 'Texte trop long (5000 caractères au plus).');
        }

        try {
            $resultat = $brouillon->preparer($agent, $audio instanceof UploadedFile ? $audio : null, $texte);
        } catch (TranscriptionImpossible $e) {
            return $this->refus('audio', $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json($resultat);
    }

    private function refus(string $champ, string $message, int $statut = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->json([
            'title' => $message,
            'detail' => $message,
            'violations' => [['propertyPath' => $champ, 'title' => $message]],
        ], $statut);
    }
}
