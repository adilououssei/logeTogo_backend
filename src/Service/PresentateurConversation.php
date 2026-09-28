<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Utilisateur;
use App\Enum\TypeMedia;

/**
 * Données d'une conversation vues par l'un de ses participants : son interlocuteur,
 * l'annonce concernée, le dernier message et le nombre de messages qu'il n'a pas lus.
 */
class PresentateurConversation
{
    /** @return array<string, mixed> */
    public function presenter(Conversation $conversation, Utilisateur $lecteur, ?Message $dernier = null, int $nonLus = 0): array
    {
        $interlocuteur = $conversation->getAutreParticipant($lecteur);
        $annonce = $conversation->getAnnonce();
        $interlocuteurEstAnnonceur = $interlocuteur === $conversation->getAnnonceur();

        $photo = null;
        foreach ($annonce?->getMedias() ?? [] as $media) {
            if (TypeMedia::IMAGE === $media->getType()) {
                $photo = $media->getUrl();
                break;
            }
        }

        return [
            'id' => $conversation->getId(),
            'annonce' => null === $annonce ? null : [
                'id' => $annonce->getId(),
                'titre' => $annonce->getTitre(),
                'photo' => $photo,
            ],
            'interlocuteur' => [
                'id' => $interlocuteur->getId(),
                'prenom' => $interlocuteur->getPrenom(),
                'nom' => $interlocuteur->getNom(),
                'avatar' => $interlocuteur->getAvatar(),
                'role' => $interlocuteur->getRole()->value,
                'verifie' => $interlocuteur->isVerifie(),
                // Seul le numéro de l'annonceur est communiqué (celui de l'annonce en priorité),
                // jamais celui de la personne qui a pris contact.
                'telephone' => $interlocuteurEstAnnonceur
                    ? ($annonce?->getContact()->getTelephone() ?? $this->telephone($interlocuteur))
                    : null,
            ],
            'dernierMessage' => null === $dernier ? null : [
                'type' => $dernier->getType()->value,
                'contenu' => $dernier->getContenu(),
                'dateEnvoi' => $dernier->getDateEnvoi()?->format(\DATE_ATOM),
                'deMoi' => $dernier->getExpediteur() === $lecteur,
            ],
            'nonLus' => $nonLus,
            'dateDernierMessage' => ($conversation->getDateDernierMessage() ?? $conversation->getDateCreation())?->format(\DATE_ATOM),
        ];
    }

    private function telephone(Utilisateur $utilisateur): ?string
    {
        $numero = $utilisateur->getTelephone();
        if (null === $numero) {
            return null;
        }

        return $utilisateur->getIndicatifPays() && !str_starts_with($numero, '+')
            ? $utilisateur->getIndicatifPays().' '.$numero
            : $numero;
    }
}
