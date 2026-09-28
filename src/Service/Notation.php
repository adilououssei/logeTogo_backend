<?php

namespace App\Service;

use App\Dto\AvisDto;
use App\Entity\Annonce;
use App\Entity\Avis;
use App\Entity\Utilisateur;
use App\Repository\AvisRepository;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Avis et notes sur les logements et les agents.
 * Pour éviter les faux avis, seul quelqu'un qui a réellement échangé avec l'annonceur
 * via la messagerie peut le noter (lui, ou le logement dont ils ont parlé).
 */
class Notation
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvisRepository $avis,
        private readonly ConversationRepository $conversations,
        private readonly ValidatorInterface $validateur,
        private readonly Notificateur $notificateur,
    ) {
    }

    /**
     * Crée l'avis de l'auteur, ou le remplace s'il en avait déjà laissé un.
     *
     * @return array{0: Avis, 1: bool} l'avis et vrai s'il vient d'être créé
     */
    public function enregistrer(Utilisateur $auteur, ?Annonce $annonce, ?Utilisateur $agent, AvisDto $donnees): array
    {
        $annonceur = $annonce?->getPubliePar() ?? $agent;
        if ($annonceur === $auteur) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas vous évaluer vous-même.');
        }
        if (!$this->conversations->aEchange($auteur, $annonceur, $annonce)) {
            throw new AccessDeniedHttpException(null !== $annonce
                ? 'Échangez d\'abord avec l\'agent au sujet de ce logement pour pouvoir le noter.'
                : 'Échangez d\'abord avec cet agent via la messagerie pour pouvoir le noter.');
        }

        $existant = $this->avis->trouverDeAuteur($auteur, $annonce, $agent);
        $avis = $existant ?? (new Avis())->setAuteur($auteur)->setAnnonce($annonce)->setAgentEvalue($agent);
        $avis->setNote($donnees->note)
            ->setCommentaire(null !== $donnees->commentaire && '' !== trim($donnees->commentaire) ? trim($donnees->commentaire) : null);

        $violations = $this->validateur->validate($avis);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($avis, $violations);
        }
        $this->em->persist($avis);
        $this->em->flush();

        if (null === $existant) {
            $this->notificateur->nouvelAvis($avis);
        }

        return [$avis, null === $existant];
    }

    public function supprimer(Utilisateur $auteur, ?Annonce $annonce, ?Utilisateur $agent): void
    {
        $avis = $this->avis->trouverDeAuteur($auteur, $annonce, $agent);
        if (null !== $avis) {
            $this->em->remove($avis);
            $this->em->flush();
        }
    }

    /**
     * Renseigne la note moyenne et le nombre d'avis des annonces et de leurs annonceurs
     * (deux requêtes, quel que soit le nombre d'annonces).
     *
     * @param iterable<Annonce> $annonces
     */
    public function completer(iterable $annonces): void
    {
        $annonces = \is_array($annonces) ? $annonces : iterator_to_array($annonces, false);
        $notesAnnonces = $this->avis->statistiques('annonce', array_map(fn (Annonce $a) => $a->getId(), $annonces));
        $annonceurs = [];
        foreach ($annonces as $annonce) {
            $stats = $notesAnnonces[$annonce->getId()] ?? null;
            $annonce->definirNotes($stats['moyenne'] ?? null, $stats['nombre'] ?? 0);
            $annonceurs[$annonce->getPubliePar()->getId()] = $annonce->getPubliePar();
        }
        $this->completerUtilisateurs(array_values($annonceurs));
    }

    /** @param list<Utilisateur> $utilisateurs */
    public function completerUtilisateurs(array $utilisateurs): void
    {
        $notes = $this->avis->statistiques('agentEvalue', array_map(fn (Utilisateur $u) => $u->getId(), $utilisateurs));
        foreach ($utilisateurs as $utilisateur) {
            $stats = $notes[$utilisateur->getId()] ?? null;
            $utilisateur->definirNotes($stats['moyenne'] ?? null, $stats['nombre'] ?? 0);
        }
    }

    /**
     * Avis visibles d'une annonce ou d'un agent, vus par $lecteur (qui retrouve le sien en tête).
     *
     * @return array<string, mixed>
     */
    public function presenter(?Annonce $annonce, ?Utilisateur $agent, ?Utilisateur $lecteur): array
    {
        $liste = $this->avis->trouverVisibles($annonce, $agent);
        $stats = null !== $annonce
            ? ($this->avis->statistiques('annonce', [$annonce->getId()])[$annonce->getId()] ?? null)
            : ($this->avis->statistiques('agentEvalue', [$agent->getId()])[$agent->getId()] ?? null);
        $monAvis = null !== $lecteur ? $this->avis->trouverDeAuteur($lecteur, $annonce, $agent) : null;
        $annonceur = $annonce?->getPubliePar() ?? $agent;

        return [
            'moyenne' => null !== $stats ? round($stats['moyenne'], 1) : null,
            'nombre' => $stats['nombre'] ?? 0,
            // Ce que l'application doit proposer au lecteur connecté.
            'peutNoter' => null !== $lecteur && $lecteur !== $annonceur && $this->conversations->aEchange($lecteur, $annonceur, $annonce),
            'monAvis' => null !== $monAvis ? $this->versTableau($monAvis, $lecteur) : null,
            'avis' => array_map(fn (Avis $a) => $this->versTableau($a, $lecteur), $liste),
        ];
    }

    /** @return array<string, mixed> */
    private function versTableau(Avis $avis, ?Utilisateur $lecteur): array
    {
        $auteur = $avis->getAuteur();

        return [
            'id' => $avis->getId(),
            'note' => $avis->getNote(),
            'commentaire' => $avis->getCommentaire(),
            'dateCreation' => $avis->getDateCreation()?->format(\DATE_ATOM),
            // Prénom et initiale du nom seulement : « Ama K. »
            'auteur' => trim($auteur->getPrenom().' '.mb_substr((string) $auteur->getNom(), 0, 1).'.'),
            'deMoi' => $auteur === $lecteur,
        ];
    }
}
