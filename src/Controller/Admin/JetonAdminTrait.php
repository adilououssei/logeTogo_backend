<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Jeton CSRF des formulaires d'action de l'administration (champ « _jeton »).
 * Un jeton invalide donne une erreur 400 : un refus d'accès déconnecterait l'administrateur.
 */
trait JetonAdminTrait
{
    private function verifierJeton(Request $requete): void
    {
        if (!$this->isCsrfTokenValid('admin', (string) $requete->request->get('_jeton'))) {
            throw new BadRequestHttpException('Jeton de sécurité invalide ou expiré. Revenez en arrière et rechargez la page.');
        }
    }
}
