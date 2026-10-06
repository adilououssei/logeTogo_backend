<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Repository\AnnonceRepository;
use App\Repository\DemandeVerificationRepository;
use App\Service\Moderation;
use App\Service\Notation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Utilisateurs (tous rôles) et agents immobiliers : consultation, suspension, badge « vérifié ». */
#[Route('/admin', name: 'admin_')]
class UtilisateurController extends AbstractController
{
    use JetonAdminTrait;

    private const PAR_PAGE = 20;

    #[Route('/utilisateurs', name: 'utilisateurs', methods: ['GET'])]
    public function liste(Request $requete, EntityManagerInterface $em): Response
    {
        return $this->render('admin/utilisateurs/liste.html.twig', $this->rechercher($requete, $em, null));
    }

    /** Agents immobiliers seulement, avec leurs annonces et leurs vues. */
    #[Route('/agents', name: 'agents', methods: ['GET'])]
    public function agents(Request $requete, EntityManagerInterface $em): Response
    {
        $donnees = $this->rechercher($requete, $em, RoleUtilisateur::AGENT);
        $ids = array_map(fn (Utilisateur $u) => $u->getId(), $donnees['utilisateurs']);
        $donnees['activite'] = [] === $ids ? [] : array_column($em->getConnection()->fetchAllAssociative(
            'SELECT publie_par_id AS id, COUNT(*) AS annonces, COALESCE(SUM(nombre_vues), 0) AS vues,
                    SUM(statut = \'disponible\') AS disponibles,
                    SUM(statut = \'a_confirmer\' OR (statut = \'disponible\' AND date_rappel_disponibilite IS NOT NULL)) AS sans_reponse
             FROM annonce WHERE publie_par_id IN (?) GROUP BY publie_par_id',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        ), null, 'id');

        return $this->render('admin/agents/liste.html.twig', $donnees);
    }

    #[Route('/utilisateurs/{id}', name: 'utilisateur', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Utilisateur $utilisateur, AnnonceRepository $annonces, DemandeVerificationRepository $demandes, Notation $notation): Response
    {
        $notation->completerUtilisateurs([$utilisateur]);

        return $this->render('admin/utilisateurs/detail.html.twig', [
            'utilisateur' => $utilisateur,
            'annonces' => $annonces->trouverParAnnonceur($utilisateur),
            'demande' => $demandes->derniere($utilisateur),
        ]);
    }

    #[Route('/utilisateurs/{id}/etat', name: 'utilisateur_etat', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changerEtat(Utilisateur $utilisateur, Request $requete, Moderation $moderation, #[CurrentUser] Utilisateur $admin): Response
    {
        $this->verifierJeton($requete);
        if ($utilisateur === $admin) {
            $this->addFlash('erreur', 'Vous ne pouvez pas suspendre votre propre compte.');
        } else {
            $actif = !$utilisateur->isEstActif();
            $moderation->changerEtatCompte($utilisateur, $actif);
            $this->addFlash('succes', \sprintf('Le compte de %s est %s.', $utilisateur->getNomComplet(), $actif ? 'réactivé' : 'suspendu : il ne peut plus se connecter'));
        }

        return $this->redirect($requete->headers->get('referer') ?? $this->generateUrl('admin_utilisateur', ['id' => $utilisateur->getId()]));
    }

    /** Accorde ou retire le badge « vérifié » (sans passer par une demande). */
    #[Route('/utilisateurs/{id}/badge', name: 'utilisateur_badge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculerBadge(Utilisateur $utilisateur, Request $requete, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($requete);
        $utilisateur->setVerifie(!$utilisateur->isVerifie());
        $em->flush();
        $this->addFlash('succes', \sprintf('Badge « vérifié » %s pour %s.', $utilisateur->isVerifie() ? 'accordé' : 'retiré', $utilisateur->getNomComplet()));

        return $this->redirect($requete->headers->get('referer') ?? $this->generateUrl('admin_utilisateur', ['id' => $utilisateur->getId()]));
    }

    /** @return array<string, mixed> */
    private function rechercher(Request $requete, EntityManagerInterface $em, ?RoleUtilisateur $roleImpose): array
    {
        $filtres = [
            'q' => trim((string) $requete->query->get('q', '')),
            'role' => $roleImpose?->value ?? (string) $requete->query->get('role', ''),
            'etat' => (string) $requete->query->get('etat', ''),
            'region' => (string) $requete->query->get('region', ''),
            'verifie' => (string) $requete->query->get('verifie', ''),
        ];
        $page = max(1, $requete->query->getInt('page', 1));

        $qb = $em->getRepository(Utilisateur::class)->createQueryBuilder('u')->orderBy('u.dateInscription', 'DESC');
        if ('' !== $filtres['q']) {
            $qb->andWhere('u.nom LIKE :q OR u.prenom LIKE :q OR u.identifiant LIKE :q OR u.email LIKE :q OR u.telephone LIKE :q')
                ->setParameter('q', '%'.$filtres['q'].'%');
        }
        if (null !== ($role = RoleUtilisateur::tryFrom($filtres['role']))) {
            $qb->andWhere('u.role = :role')->setParameter('role', $role);
        }
        if (null !== ($region = Region::tryFrom($filtres['region']))) {
            $qb->andWhere('u.region = :region')->setParameter('region', $region);
        }
        if (\in_array($filtres['etat'], ['actif', 'suspendu'], true)) {
            $qb->andWhere('u.estActif = :actif')->setParameter('actif', 'actif' === $filtres['etat']);
        }
        if (\in_array($filtres['verifie'], ['oui', 'non'], true)) {
            $qb->andWhere('u.verifie = :verifie')->setParameter('verifie', 'oui' === $filtres['verifie']);
        }

        $paginateur = new Paginator($qb->setFirstResult(($page - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE));

        return [
            'utilisateurs' => iterator_to_array($paginateur),
            'total' => \count($paginateur),
            'page' => $page,
            'pages' => max(1, (int) ceil(\count($paginateur) / self::PAR_PAGE)),
            'filtres' => $filtres,
            'roles' => RoleUtilisateur::cases(),
            'regions' => Region::cases(),
        ];
    }
}
