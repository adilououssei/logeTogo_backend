<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Une erreur de validation levée par un service (email déjà utilisé, fichier trop
 * lourd…) devient une réponse 422 avec la liste des violations — le même format que
 * la validation automatique des données reçues (#[MapRequestPayload]).
 */
final class ErreursValidationListener
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function convertir(ExceptionEvent $evenement): void
    {
        $exception = $evenement->getThrowable();
        if ($exception instanceof ValidationFailedException) {
            $evenement->setThrowable(new UnprocessableEntityHttpException($exception->getMessage(), $exception));
        }
    }
}
