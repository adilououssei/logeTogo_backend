<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

/**
 * Exports de l'administration (utilisateurs, biens) en Excel (.xlsx) ou en PDF.
 * Les deux formats partagent les mêmes colonnes et les mêmes lignes.
 */
class ExportAdmin
{
    /** Marron de LogeTogo (charte graphique). */
    private const COULEUR = '8F3400';

    /** Logos haute définition, recadrés, réservés aux PDF : complet (page de garde) et pictogramme (en-têtes). */
    private const LOGO = '/assets/images/logo-pdf.png';
    private const PICTOGRAMME = '/assets/images/logo-pdf-icone.png';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $dossierProjet,
    ) {
    }

    public function excel(string $type): StreamedResponse
    {
        $donnees = $this->donnees($type);

        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator('LogeTogo')->setTitle($donnees['titre']);
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle($donnees['titre']);
        $feuille->fromArray($donnees['colonnes']);
        // Textes écrits tels quels : sinon Excel ferait d'un téléphone « +22890… » un nombre.
        foreach ($donnees['lignes'] as $i => $ligne) {
            foreach ($ligne as $j => $valeur) {
                if (null === $valeur) {
                    continue;
                }
                $cellule = [$j + 1, $i + 2];
                \is_int($valeur)
                    ? $feuille->setCellValue($cellule, $valeur)
                    : $feuille->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
            }
        }

        $derniere = Coordinate::stringFromColumnIndex(\count($donnees['colonnes']));
        $entete = $feuille->getStyle('A1:'.$derniere.'1');
        $entete->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $entete->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COULEUR);
        foreach ($donnees['nombres'] as $j) {
            $colonne = Coordinate::stringFromColumnIndex($j + 1);
            $feuille->getStyle($colonne.'2:'.$colonne.(\count($donnees['lignes']) + 1))->getNumberFormat()->setFormatCode('#,##0');
        }
        $feuille->freezePane('A2');
        $feuille->setAutoFilter('A1:'.$derniere.(\count($donnees['lignes']) + 1));
        for ($i = 1; $i <= \count($donnees['colonnes']); ++$i) {
            $feuille->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $reponse = new StreamedResponse(fn () => (new Xlsx($classeur))->save('php://output'));
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Content-Disposition', $this->piece($type, 'xlsx'));

        return $reponse;
    }

    /**
     * Page de garde (logo, titre, chiffres clés), puis le tableau sur les pages suivantes,
     * avec en-tête et numéro de page.
     */
    public function pdf(string $type, ?string $auteur = null): Response
    {
        $donnees = $this->donnees($type);

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans'); // accents et caractères spéciaux
        $options->setChroot($this->dossierProjet);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('admin/export/pdf.html.twig', $donnees + [
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->dossierProjet.self::LOGO)),
            'genereLe' => new \DateTimeImmutable(),
            'auteur' => $auteur,
        ]));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // En-tête et pied dessinés sur chaque page, sauf la page de garde.
        $titre = $donnees['intitule'];
        $pictogramme = $this->dossierProjet.self::PICTOGRAMME;
        $dompdf->getCanvas()->page_script(function (int $page, int $pages, Canvas $canvas, FontMetrics $polices) use ($pictogramme, $titre) {
            if (1 === $page) {
                return;
            }
            $largeur = $canvas->get_width();
            $hauteur = $canvas->get_height();
            $marron = [0x8F / 255, 0x34 / 255, 0];
            $gris = [0.45, 0.45, 0.45];
            $normale = $polices->getFont('DejaVu Sans');
            $grasse = $polices->getFont('DejaVu Sans', 'bold');

            $canvas->image($pictogramme, 34, 17, 30, 30);
            $canvas->text(70, 20, 'LogeTogo', $grasse, 11, $marron);
            $canvas->text(70, 35, $titre, $normale, 8, $gris);
            $canvas->line(34, 54, $largeur - 34, 54, $marron, 1.2);

            $canvas->line(34, $hauteur - 34, $largeur - 34, $hauteur - 34, [0.85, 0.85, 0.85], 0.6);
            $canvas->text(34, $hauteur - 27, 'Document confidentiel · usage interne', $normale, 7, $gris);
            $numero = \sprintf('Page %d / %d', $page, $pages);
            $canvas->text($largeur - 34 - $polices->getTextWidth($numero, $normale, 7), $hauteur - 27, $numero, $normale, 7, $gris);
        });

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->piece($type, 'pdf'),
        ]);
    }

    /**
     * « nombres » : index des colonnes numériques (alignées à droite et séparées par milliers dans le PDF).
     * « resume » : chiffres clés affichés sur la page de garde du PDF.
     *
     * @return array{titre: string, intitule: string, colonnes: list<string>, nombres: list<int>, lignes: list<list<mixed>>, resume: array<string, int>}
     */
    private function donnees(string $type): array
    {
        if ('utilisateurs' === $type) {
            $lignes = [];
            $resume = ['Comptes' => 0, 'Identités vérifiées' => 0, 'Comptes actifs' => 0, 'Comptes suspendus' => 0];
            foreach ($this->em->getRepository(Utilisateur::class)->findBy([], ['dateInscription' => 'DESC']) as $u) {
                ++$resume['Comptes'];
                $resume['Identités vérifiées'] += (int) $u->isVerifie();
                $u->isEstActif() ? ++$resume['Comptes actifs'] : ++$resume['Comptes suspendus'];
                $lignes[] = [
                    $u->getIdentifiant(), $u->getPrenom(), $u->getNom(), $u->getRole()->libelle(), $u->getRegion()?->libelle(),
                    $u->getEmail(), $u->getTelephone(), $u->isVerifie() ? 'oui' : 'non', $u->isEstActif() ? 'oui' : 'non',
                    $u->getDateInscription()?->format('d/m/Y H:i'),
                ];
            }

            return [
                'titre' => 'Utilisateurs',
                'intitule' => 'Liste des utilisateurs',
                'colonnes' => ['Identifiant', 'Prénom', 'Nom', 'Type', 'Région', 'Email', 'Téléphone', 'Vérifié', 'Actif', 'Inscription'],
                'nombres' => [],
                'lignes' => $lignes,
                'resume' => $resume,
            ];
        }

        $lignes = [];
        $resume = ['Annonces' => 0, 'Disponibles' => 0, 'En location' => 0, 'En vente' => 0, 'Vues cumulées' => 0];
        foreach ($this->em->getRepository(Annonce::class)->findBy([], ['datePublication' => 'DESC']) as $a) {
            ++$resume['Annonces'];
            $resume['Disponibles'] += (int) (StatutAnnonce::DISPONIBLE === $a->getStatut());
            $resume['En location'] += (int) (TypeTransaction::LOCATION === $a->getTypeTransaction());
            $resume['En vente'] += (int) (TypeTransaction::VENTE === $a->getTypeTransaction());
            $resume['Vues cumulées'] += $a->getNombreVues();
            $l = $a->getLocalisation();
            $lignes[] = [
                $a->getId(), $a->getTitre(), $a->getTypeBien()?->libelle(), $a->getTypeTransaction()?->libelle(), $a->getPrix(),
                $a->getStatut()->libelle(), $l->getRegion()?->libelle(), $l->getVille(), $l->getQuartier(), $a->getNombreVues(),
                $a->getPubliePar()?->getNomComplet(), $a->getDatePublication()?->format('d/m/Y H:i'),
            ];
        }

        return [
            'titre' => 'Biens',
            'intitule' => 'Liste des biens immobiliers',
            'colonnes' => ['N°', 'Titre', 'Type', 'Transaction', 'Prix (FCFA)', 'Statut', 'Région', 'Ville', 'Quartier', 'Vues', 'Annonceur', 'Publication'],
            'nombres' => [4, 9],
            'lignes' => $lignes,
            'resume' => $resume,
        ];
    }

    private function piece(string $type, string $extension): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('logetogo-%s-%s.%s', $type, date('Y-m-d'), $extension),
        );
    }
}
