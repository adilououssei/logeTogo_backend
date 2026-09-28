<?php

namespace App\Controller\Admin;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\StatutAnnonce;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use App\Repository\SignalementRepository;
use App\Service\GestionAnnonces;
use App\Service\Moderation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Biens immobiliers (annonces) : consultation, suspension, remise en ligne, suppression. */
#[Route('/admin/biens', name: 'admin_')]
class BienController extends AbstractController
{
    use JetonAdminTrait;

    private const PAR_PAGE = 20;

    #[Route('', name: 'biens', methods: ['GET'])]
    public function liste(Request $requete, EntityManagerInterface $em): Response
    {
        $filtres = [
            'q' => trim((string) $requete->query->get('q', '')),
            'type' => (string) $requete->query->get('type', ''),
            'transaction' => (string) $requete->query->get('transaction', ''),
            'statut' => (string) $requete->query->get('statut', ''),
            'region' => (string) $requete->query->get('region', ''),
        ];
        $page = max(1, $requete->query->getInt('page', 1));

        $qb = $em->getRepository(Annonce::class)->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.publiePar', 'p')
            ->orderBy('a.datePublication', 'DESC')
            ->addOrderBy('a.id', 'DESC');
        if ('' !== $filtres['q']) {
            $qb->andWhere('a.titre LIKE :q OR a.localisation.ville LIKE :q OR a.localisation.quartier LIKE :q OR p.nom LIKE :q OR p.prenom LIKE :q')
                ->setParameter('q', '%'.$filtres['q'].'%');
        }
        foreach ([
            'type' => ['a.typeBien', TypeBien::tryFrom($filtres['type'])],
            'transaction' => ['a.typeTransaction', TypeTransaction::tryFrom($filtres['transaction'])],
            'statut' => ['a.statut', StatutAnnonce::tryFrom($filtres['statut'])],
            'region' => ['a.localisation.region', Region::tryFrom($filtres['region'])],
        ] as $cle => [$champ, $valeur]) {
            if (null !== $valeur) {
                $qb->andWhere("$champ = :$cle")->setParameter($cle, $valeur);
            }
        }

        $paginateur = new Paginator($qb->setFirstResult(($page - 1) * self::PAR_PAGE)->setMaxResults(self::PAR_PAGE));

        return $this->render('admin/biens/liste.html.twig', [
            'annonces' => iterator_to_array($paginateur),
            'total' => \count($paginateur),
            'page' => $page,
            'pages' => max(1, (int) ceil(\count($paginateur) / self::PAR_PAGE)),
            'filtres' => $filtres,
            'types' => TypeBien::cases(),
            'statuts' => StatutAnnonce::cases(),
            'regions' => Region::cases(),
        ]);
    }

    #[Route('/{id}', name: 'bien', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Annonce $annonce, SignalementRepository $signalements): Response
    {
        return $this->render('admin/biens/detail.html.twig', [
            'annonce' => $annonce,
            'signalements' => $signalements->findBy(['annonce' => $annonce], ['dateCreation' => 'DESC']),
        ]);
    }

    #[Route('/{id}/suspendre', name: 'bien_suspendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function suspendre(Annonce $annonce, Request $requete, Moderation $moderation, #[CurrentUser] Utilisateur $admin): Response
    {
        $this->verifierJeton($requete);
        $moderation->suspendreAnnonce($annonce, $admin, trim((string) $requete->request->get('motif', '')) ?: null);
        $this->addFlash('succes', \sprintf('Annonce « %s » suspendue : elle n\'apparaît plus dans l\'application. Son auteur a été prévenu.', $annonce->getTitre()));

        return $this->redirect($requete->headers->get('referer') ?? $this->generateUrl('admin_bien', ['id' => $annonce->getId()]));
    }

    #[Route('/{id}/reactiver', name: 'bien_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reactiver(Annonce $annonce, Request $requete, Moderation $moderation, #[CurrentUser] Utilisateur $admin): Response
    {
        $this->verifierJeton($requete);
        $moderation->reactiverAnnonce($annonce, $admin);
        $this->addFlash('succes', \sprintf('Annonce « %s » remise en ligne.', $annonce->getTitre()));

        return $this->redirect($requete->headers->get('referer') ?? $this->generateUrl('admin_bien', ['id' => $annonce->getId()]));
    }

    #[Route('/{id}/supprimer', name: 'bien_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Annonce $annonce, Request $requete, GestionAnnonces $gestion): Response
    {
        $this->verifierJeton($requete);
        $titre = $annonce->getTitre();
        $gestion->supprimer($annonce);
        $this->addFlash('succes', \sprintf('Annonce « %s » supprimée définitivement, avec ses photos et vidéos.', $titre));

        return $this->redirectToRoute('admin_biens');
    }
}
