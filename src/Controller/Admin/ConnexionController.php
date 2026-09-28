<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Connexion à l'interface d'administration (formulaire traité par le pare-feu « admin »). */
class ConnexionController extends AbstractController
{
    #[Route('/admin/connexion', name: 'admin_connexion', methods: ['GET', 'POST'])]
    public function connexion(AuthenticationUtils $authentification): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_tableau_de_bord');
        }

        return $this->render('admin/connexion.html.twig', [
            'erreur' => $authentification->getLastAuthenticationError(),
            'dernierIdentifiant' => $authentification->getLastUsername(),
        ]);
    }

    #[Route('/admin/deconnexion', name: 'admin_deconnexion', methods: ['GET'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Interceptée par le pare-feu « admin ».');
    }
}
