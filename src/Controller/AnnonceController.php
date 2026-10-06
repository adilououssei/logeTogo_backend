<?php

namespace App\Controller;

use App\Dto\AnnonceDto;
use App\Dto\ChangementStatutDto;
use App\Dto\FiltresAnnoncesDto;
use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Repository\AnnonceRepository;
use App\Security\Voter\AnnonceVoter;
use App\Service\GestionAnnonces;
use App\Service\SuiviDisponibilite;
use App\Service\Notation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annonces de logements.
 *
 * Visiteurs : liste et détail, sans coordonnées GPS ni contacts.
 * Connectés : détail complet (adresse, GPS, téléphones de l'annonceur).
 * Agents et propriétaires : publier, modifier, changer le statut, supprimer leurs annonces.
 */
#[Route('/api/annonces', name: 'api_annonces_', format: 'json')]
class AnnonceController extends AbstractController
{
    private const GROUPES_LISTE = ['annonce:liste', 'annonceur:public'];
    private const GROUPES_DETAIL = ['annonce:detail', 'annonceur:public'];
    private const GROUPES_PRIVES = ['annonce:prive', 'annonceur:prive'];
    /** Réservé à l'auteur de l'annonce : rappels de disponibilité. */
    private const GROUPE_ANNONCEUR = 'annonce:annonceur';

    /**
     * Liste paginée : {"elements": [...], "total": 42, "page": 1, "parPage": 20, "pages": 3}.
     * Biens disponibles uniquement. Filtres : recherche, region, quartier, typeBien, typeTransaction, prixMin, prixMax, regionPrioritaire, page, parPage.
     */
    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(
        AnnonceRepository $annonces,
        Notation $notation,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] FiltresAnnoncesDto $filtres = new FiltresAnnoncesDto(),
    ): JsonResponse {
        $resultats = $annonces->rechercher($filtres);
        $total = \count($resultats);
        $elements = iterator_to_array($resultats, false);
        $notation->completer($elements);

        return $this->json([
            'elements' => $elements,
            'total' => $total,
            'page' => $filtres->page,
            'parPage' => $filtres->parPage,
            'pages' => (int) ceil($total / $filtres->parPage),
        ], context: ['groups' => self::GROUPES_LISTE]);
    }

    /** Annonces de l'agent ou du propriétaire connecté, tous statuts confondus (tableaux de bord). */
    #[Route('/mes-annonces', name: 'mes_annonces', methods: ['GET'])]
    #[IsGranted(AnnonceVoter::PUBLIER, message: 'Réservé aux agents et propriétaires.')]
    public function mesAnnonces(#[CurrentUser] Utilisateur $utilisateur, AnnonceRepository $annonces, Notation $notation): JsonResponse
    {
        $liste = $annonces->trouverParAnnonceur($utilisateur);
        $notation->completer($liste);

        return $this->json(
            $liste,
            context: ['groups' => [...self::GROUPES_DETAIL, ...self::GROUPES_PRIVES, self::GROUPE_ANNONCEUR]],
        );
    }

    /** Détail d'une annonce ; chaque consultation par un tiers compte une vue. */
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(AnnonceVoter::VOIR, subject: 'annonce', message: 'Annonce introuvable.', statusCode: 404)]
    public function detail(Annonce $annonce, EntityManagerInterface $em, Notation $notation, #[CurrentUser] ?Utilisateur $utilisateur = null): JsonResponse
    {
        if ($annonce->getPubliePar() !== $utilisateur) {
            $annonce->incrementerVues();
            $em->flush();
        }
        $notation->completer([$annonce]);

        return $this->json($annonce, context: ['groups' => $this->groupesDetail($utilisateur, $annonce)]);
    }

    /** Publie une annonce (201). Les photos et vidéos s'ajoutent ensuite via /api/annonces/{id}/medias. */
    #[Route('', name: 'publier', methods: ['POST'])]
    #[IsGranted(AnnonceVoter::PUBLIER, message: 'Seuls les agents et les propriétaires peuvent publier une annonce.')]
    public function publier(
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AnnonceDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        GestionAnnonces $gestion,
    ): JsonResponse {
        $annonce = $gestion->publier($donnees, $utilisateur);

        return $this->json($annonce, Response::HTTP_CREATED, context: ['groups' => $this->groupesDetail($utilisateur, $annonce)]);
    }

    /** Remplace le contenu de l'annonce (auteur ou administrateur). */
    #[Route('/{id}', name: 'modifier', requirements: ['id' => '\d+'], methods: ['PUT'])]
    #[IsGranted(AnnonceVoter::MODIFIER, subject: 'annonce', message: 'Vous ne pouvez modifier que vos propres annonces.')]
    public function modifier(
        Annonce $annonce,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AnnonceDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        GestionAnnonces $gestion,
    ): JsonResponse {
        return $this->json($gestion->modifier($annonce, $donnees), context: ['groups' => $this->groupesDetail($utilisateur, $annonce)]);
    }

    /**
     * L'annonceur confirme que son bien est toujours disponible : il reste (ou revient) en ligne
     * et le prochain rappel n'aura lieu que dans 7 jours.
     */
    #[Route('/{id}/confirmer-disponibilite', name: 'confirmer_disponibilite', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(AnnonceVoter::MODIFIER, subject: 'annonce', message: 'Vous ne pouvez confirmer que vos propres annonces.')]
    public function confirmerDisponibilite(Annonce $annonce, #[CurrentUser] Utilisateur $utilisateur, SuiviDisponibilite $suivi): JsonResponse
    {
        $suivi->confirmer($annonce);

        return $this->json($annonce, context: ['groups' => $this->groupesDetail($utilisateur, $annonce)]);
    }

    /**
     * {"statut": "disponible" | "occupe" | "vendu"} — « suspendu » est réservé aux administrateurs,
     * « a_confirmer » est attribué automatiquement. Repasser en « disponible » vaut confirmation.
     */
    #[Route('/{id}/statut', name: 'statut', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    #[IsGranted(AnnonceVoter::MODIFIER, subject: 'annonce', message: 'Vous ne pouvez modifier que vos propres annonces.')]
    public function changerStatut(
        Annonce $annonce,
        #[MapRequestPayload(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ChangementStatutDto $donnees,
        #[CurrentUser] Utilisateur $utilisateur,
        GestionAnnonces $gestion,
    ): JsonResponse {
        $gestion->changerStatut($annonce, $donnees->statut, $utilisateur);

        return $this->json($annonce, context: ['groups' => $this->groupesDetail($utilisateur, $annonce)]);
    }

    /** Supprime l'annonce, ses photos et vidéos (204). */
    #[Route('/{id}', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted(AnnonceVoter::SUPPRIMER, subject: 'annonce', message: 'Vous ne pouvez supprimer que vos propres annonces.')]
    public function supprimer(Annonce $annonce, GestionAnnonces $gestion): Response
    {
        $gestion->supprimer($annonce);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return list<string> */
    private function groupesDetail(?Utilisateur $utilisateur, Annonce $annonce): array
    {
        if (null === $utilisateur) {
            return self::GROUPES_DETAIL;
        }
        $groupes = [...self::GROUPES_DETAIL, ...self::GROUPES_PRIVES];

        return $annonce->getPubliePar() === $utilisateur ? [...$groupes, self::GROUPE_ANNONCEUR] : $groupes;
    }
}
