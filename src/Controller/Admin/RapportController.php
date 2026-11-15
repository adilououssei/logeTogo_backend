<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Service\ExportAdmin;
use App\Service\StatistiquesAdmin;
use App\Service\TelechargementsPlayStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Rapports d'activité et exports Excel et PDF. */
#[Route('/admin/rapports', name: 'admin_')]
class RapportController extends AbstractController
{
    #[Route('', name: 'rapports', methods: ['GET'])]
    public function index(StatistiquesAdmin $statistiques, TelechargementsPlayStore $playStore): Response
    {
        return $this->render('admin/rapports.html.twig', [
            'indicateurs' => $statistiques->indicateurs(),
            'activite' => $statistiques->activite(),
            'inscriptions' => $statistiques->evolutionInscriptions(12),
            'annonces' => $statistiques->evolutionAnnonces(12),
            'regions' => $statistiques->parRegion(),
            'repartition' => $statistiques->repartitionTypes(),
            'telechargements' => $playStore->statistiques(),
            'meilleursAnnonceurs' => $statistiques->meilleursAnnonceurs(10),
        ]);
    }

    #[Route('/telechargements/actualiser', name: 'rapport_telechargements_actualiser', methods: ['POST'])]
    public function actualiserTelechargements(Request $requete, TelechargementsPlayStore $playStore): RedirectResponse
    {
        if ($this->isCsrfTokenValid('telechargements', (string) $requete->request->get('_jeton'))) {
            $playStore->statistiques(true);
        }

        return $this->redirectToRoute('admin_rapports');
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
