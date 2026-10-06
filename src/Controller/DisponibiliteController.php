<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Enum\StatutAnnonce;
use App\Enum\TypeTransaction;
use App\Service\SuiviDisponibilite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réponse au rappel de disponibilité depuis l'email, sans se connecter : le lien est signé.
 * Ouvrir le lien n'affiche qu'une page de confirmation (certaines messageries « visitent »
 * les liens des emails) ; c'est le bouton de cette page qui enregistre la réponse.
 */
class DisponibiliteController extends AbstractController
{
    #[Route('/disponibilite/{id}/{reponse}', name: 'disponibilite_repondre', requirements: ['id' => '\d+', 'reponse' => 'disponible|pris'], methods: ['GET', 'POST'])]
    public function repondre(Request $requete, Annonce $annonce, string $reponse, UriSigner $signataire, SuiviDisponibilite $suivi): Response
    {
        $vente = TypeTransaction::VENTE === $annonce->getTypeTransaction();
        $page = ['annonce' => $annonce, 'reponse' => $reponse, 'pris' => $vente ? 'vendu' : 'loué'];

        if (!$signataire->checkRequest($requete) || StatutAnnonce::SUSPENDU === $annonce->getStatut()) {
            return $this->render('disponibilite/reponse.html.twig', [...$page, 'etat' => 'invalide'], new Response(status: Response::HTTP_FORBIDDEN));
        }
        if ($requete->isMethod('GET')) {
            return $this->render('disponibilite/reponse.html.twig', [...$page, 'etat' => 'question']);
        }

        'disponible' === $reponse ? $suivi->confirmer($annonce) : $suivi->marquerPris($annonce);

        return $this->render('disponibilite/reponse.html.twig', [...$page, 'etat' => 'enregistre']);
    }
}
