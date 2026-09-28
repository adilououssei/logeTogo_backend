<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Entity\Conversation;
use App\Entity\Favori;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeMedia;
use App\Repository\AnnonceRepository;
use App\Security\Voter\AnnonceVoter;
use App\Service\Notation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques de l'agent ou du propriétaire connecté : pour chaque annonce, les vues,
 * les mises en favori et les personnes qui l'ont contacté via la messagerie.
 */
#[Route('/api/statistiques', name: 'api_statistiques_', format: 'json')]
#[IsGranted(AnnonceVoter::PUBLIER, message: 'Réservé aux agents et propriétaires.')]
class StatistiqueController extends AbstractController
{
    #[Route('', name: 'mes_annonces', methods: ['GET'])]
    public function mesAnnonces(
        #[CurrentUser] Utilisateur $utilisateur,
        AnnonceRepository $annonces,
        EntityManagerInterface $em,
        Notation $notation,
    ): JsonResponse {
        $liste = $annonces->trouverParAnnonceur($utilisateur);
        $ids = array_map(fn (Annonce $a) => $a->getId(), $liste);
        $favoris = $this->compterParAnnonce($em, Favori::class, $ids);
        $contacts = $this->compterParAnnonce($em, Conversation::class, $ids);
        $notation->completer($liste);
        // Note de l'annonceur lui-même (aussi quand il n'a encore aucune annonce).
        $notation->completerUtilisateurs([$utilisateur]);

        $lignes = [];
        foreach ($liste as $annonce) {
            $photo = $annonce->getMedias()->findFirst(fn ($_, $m) => TypeMedia::IMAGE === $m->getType());
            $lignes[] = [
                'id' => $annonce->getId(),
                'titre' => $annonce->getTitre(),
                'statut' => $annonce->getStatut()->value,
                'photo' => $photo?->getUrl(),
                'datePublication' => $annonce->getDatePublication()?->format(\DATE_ATOM),
                'vues' => $annonce->getNombreVues(),
                'favoris' => $favoris[$annonce->getId()] ?? 0,
                'contacts' => $contacts[$annonce->getId()] ?? 0,
                'noteMoyenne' => $annonce->getNoteMoyenne(),
                'nombreAvis' => $annonce->getNombreAvis(),
            ];
        }
        // Les annonces les plus consultées d'abord.
        usort($lignes, fn (array $a, array $b) => [$b['vues'], $b['contacts']] <=> [$a['vues'], $a['contacts']]);

        return $this->json([
            'totaux' => [
                'annonces' => \count($lignes),
                'disponibles' => \count(array_filter($liste, fn (Annonce $a) => StatutAnnonce::DISPONIBLE === $a->getStatut())),
                'vues' => array_sum(array_column($lignes, 'vues')),
                'favoris' => array_sum(array_column($lignes, 'favoris')),
                'contacts' => array_sum(array_column($lignes, 'contacts')),
                'noteMoyenne' => $utilisateur->getNoteMoyenne(),
                'nombreAvis' => $utilisateur->getNombreAvis(),
            ],
            'annonces' => $lignes,
        ]);
    }

    /**
     * @param class-string $entite Favori ou Conversation (les deux ont une relation « annonce »)
     * @param list<int>    $ids
     *
     * @return array<int, int> nombre par identifiant d'annonce
     */
    private function compterParAnnonce(EntityManagerInterface $em, string $entite, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $lignes = $em->createQueryBuilder()
            ->select('IDENTITY(e.annonce) AS annonce', 'COUNT(e.id) AS nombre')
            ->from($entite, 'e')
            ->andWhere('e.annonce IN (:ids)')
            ->setParameter('ids', $ids)
            ->groupBy('e.annonce')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(fn (array $l) => ['id' => (int) $l['annonce'], 'n' => (int) $l['nombre']], $lignes), 'n', 'id');
    }
}
