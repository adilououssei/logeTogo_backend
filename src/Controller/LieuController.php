<?php

namespace App\Controller;

use App\Service\ReferentielQuartiers;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/** Lieux du Togo connus de l'application (liste officielle et quartiers ajoutés par les agents). */
#[Route('/api/lieux', name: 'api_lieux_', format: 'json')]
class LieuController extends AbstractController
{
    /**
     * Suggestions pendant la saisie d'un quartier : GET /api/lieux/quartiers?q=ago&ville=Lomé
     * Réponse : [{"nom": "Agoè", "ville": "Lomé", "region": "maritime"}, …] (8 au plus).
     */
    #[Route('/quartiers', name: 'quartiers', methods: ['GET'])]
    public function quartiers(ReferentielQuartiers $quartiers, #[MapQueryParameter] string $q = '', #[MapQueryParameter] ?string $ville = null): JsonResponse
    {
        return $this->json($quartiers->suggerer(mb_substr($q, 0, 50), $ville));
    }
}
