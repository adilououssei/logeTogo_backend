<?php

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Un agent, un propriétaire ou un locataire qui se connecte à l'administration
 * est aussitôt déconnecté et renvoyé vers la page de connexion avec un message.
 */
class AccesAdminRefuse implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Security $securite,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $this->securite->logout(false);
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('erreur', 'Accès réservé aux administrateurs de LogeTogo.');
        }

        return new RedirectResponse($this->urls->generate('admin_connexion'));
    }
}
