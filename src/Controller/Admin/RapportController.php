<?php

namespace App\Controller\Admin;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Service\StatistiquesAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Rapports d'activité et exports CSV (ouvrables dans Excel). */
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

    #[Route('/export/{type}', name: 'rapport_export', requirements: ['type' => 'utilisateurs|biens'], methods: ['GET'])]
    public function exporter(string $type, EntityManagerInterface $em): StreamedResponse
    {
        $reponse = new StreamedResponse(function () use ($type, $em) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            if ('utilisateurs' === $type) {
                fputcsv($sortie, ['Identifiant', 'Prénom', 'Nom', 'Rôle', 'Région', 'Email', 'Téléphone', 'Vérifié', 'Actif', 'Inscription'], ';', '"', '');
                foreach ($em->getRepository(Utilisateur::class)->findBy([], ['dateInscription' => 'DESC']) as $u) {
                    fputcsv($sortie, [
                        $u->getIdentifiant(), $u->getPrenom(), $u->getNom(), $u->getRole()->libelle(), $u->getRegion()?->libelle(),
                        $u->getEmail(), $u->getTelephone(), $u->isVerifie() ? 'oui' : 'non', $u->isEstActif() ? 'oui' : 'non',
                        $u->getDateInscription()?->format('d/m/Y H:i'),
                    ], ';', '"', '');
                }
            } else {
                fputcsv($sortie, ['N°', 'Titre', 'Type', 'Transaction', 'Prix (FCFA)', 'Statut', 'Région', 'Ville', 'Quartier', 'Vues', 'Annonceur', 'Publication'], ';', '"', '');
                foreach ($em->getRepository(Annonce::class)->findBy([], ['datePublication' => 'DESC']) as $a) {
                    $l = $a->getLocalisation();
                    fputcsv($sortie, [
                        $a->getId(), $a->getTitre(), $a->getTypeBien()?->libelle(), $a->getTypeTransaction()?->libelle(), $a->getPrix(),
                        $a->getStatut()->libelle(), $l->getRegion()?->libelle(), $l->getVille(), $l->getQuartier(), $a->getNombreVues(),
                        $a->getPubliePar()?->getNomComplet(), $a->getDatePublication()?->format('d/m/Y H:i'),
                    ], ';', '"', '');
                }
            }
            fclose($sortie);
        });
        $nom = \sprintf('logetogo-%s-%s.csv', $type, date('Y-m-d'));
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="'.$nom.'"');

        return $reponse;
    }
}
