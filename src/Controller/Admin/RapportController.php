<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Service\ExportAdmin;
use App\Service\StatistiquesAdmin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Rapports d'activité et exports Excel et PDF. */
#[Route('/admin/rapports', name: 'admin_')]
class RapportController extends AbstractController
{
    #[Route('', name: 'rapports', methods: ['GET'])]
    public function index(StatistiquesAdmin $statistiques): Response
    {
        return $this->render('admin/rapports.html.twig', [
            'indicateurs' => $statistiques->indicateurs(),
            'activite' => $statistiques->activite(),
            'inscriptions' => $statistiques->evolutionInscriptions(12),
            'annonces' => $statistiques->evolutionAnnonces(12),
            'regions' => $statistiques->parRegion(),
            'repartition' => $statistiques->repartitionTypes(),
            'meilleursAnnonceurs' => $statistiques->meilleursAnnonceurs(10),
        ]);
    }

    #[Route('/export/{type}/{format}', name: 'rapport_export', requirements: ['type' => 'utilisateurs|biens', 'format' => 'excel|pdf'], methods: ['GET'])]
    public function exporter(string $type, string $format, ExportAdmin $export): Response
    {
        $admin = $this->getUser();

        return 'pdf' === $format
            ? $export->pdf($type, $admin instanceof Utilisateur ? $admin->getNomComplet() : null)
            : $export->excel($type);
    }
}
