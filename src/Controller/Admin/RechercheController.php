<?php

namespace App\Controller\Admin;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Recherche de la barre du haut : utilisateurs, agents et biens à la fois. */
class RechercheController extends AbstractController
{
    #[Route('/admin/recherche', name: 'admin_recherche', methods: ['GET'])]
    public function rechercher(Request $requete, EntityManagerInterface $em): Response
    {
        $q = trim((string) $requete->query->get('q', ''));
        $utilisateurs = $annonces = [];
        if (mb_strlen($q) >= 2) {
            $motif = '%'.$q.'%';
            $utilisateurs = $em->getRepository(Utilisateur::class)->createQueryBuilder('u')
                ->andWhere('u.nom LIKE :q OR u.prenom LIKE :q OR u.identifiant LIKE :q OR u.email LIKE :q OR u.telephone LIKE :q OR CONCAT(u.prenom, \' \', u.nom) LIKE :q')
                ->setParameter('q', $motif)
                ->orderBy('u.dateInscription', 'DESC')
                ->setMaxResults(20)
                ->getQuery()->getResult();
            $annonces = $em->getRepository(Annonce::class)->createQueryBuilder('a')
                ->addSelect('p')
                ->join('a.publiePar', 'p')
                ->andWhere('a.titre LIKE :q OR a.localisation.ville LIKE :q OR a.localisation.quartier LIKE :q')
                ->setParameter('q', $motif)
                ->orderBy('a.datePublication', 'DESC')
                ->setMaxResults(20)
                ->getQuery()->getResult();
        }

        return $this->render('admin/recherche.html.twig', ['q' => $q, 'utilisateurs' => $utilisateurs, 'annonces' => $annonces]);
    }
}
