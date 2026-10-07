<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques exigées par Google Play : lisibles par tous, sans connexion.
 */
class PagePubliqueController extends AbstractController
{
    #[Route('/confidentialite', name: 'page_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('pages/confidentialite.html.twig');
    }

    #[Route('/suppression-compte', name: 'page_suppression_compte', methods: ['GET'])]
    public function suppressionCompte(): Response
    {
        return $this->render('pages/suppression_compte.html.twig');
    }
}
