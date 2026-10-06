<?php

namespace App\Controller\Admin;

use App\Entity\Annonce;
use App\Service\StatistiquesAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TableauBordController extends AbstractController
{
    #[Route('/admin', name: 'admin_tableau_de_bord', methods: ['GET'])]
    public function index(StatistiquesAdmin $statistiques): Response
    {
        // La requête des derniers biens ramène une ligne par média : on garde 4 annonces distinctes.
        $derniersBiens = [];
        foreach ($statistiques->derniersBiens() as $annonce) {
            \assert($annonce instanceof Annonce);
            $derniersBiens[$annonce->getId()] ??= $annonce;
        }

        return $this->render('admin/tableau_de_bord.html.twig', [
            'indicateurs' => $statistiques->indicateurs(),
            'aConfirmer' => $statistiques->annoncesAConfirmer(),
            'evolution' => $statistiques->evolutionAnnonces(6),
            'repartition' => $statistiques->repartitionTypes(),
            'activite' => $statistiques->activiteRecente(6),
            'derniersBiens' => \array_slice($derniersBiens, 0, 4),
            'meilleursAnnonceurs' => $statistiques->meilleursAnnonceurs(5),
        ]);
    }
}
