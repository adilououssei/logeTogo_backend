<?php

namespace App\Command;

use App\Service\SuiviDisponibilite;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * À lancer une fois par jour (Planificateur de tâches Windows, cron sur le serveur) :
 *   php bin/console app:annonces:suivi-disponibilite
 * Envoie les rappels « toujours disponible ? » et retire les annonces restées sans réponse.
 */
#[AsCommand(name: 'app:annonces:suivi-disponibilite', description: 'Rappels de disponibilité aux annonceurs et retrait des annonces non confirmées')]
class SuiviDisponibiliteCommand
{
    public function __construct(private readonly SuiviDisponibilite $suivi)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $bilan = $this->suivi->verifier();
        $io->success(\sprintf('%d rappel(s) envoyé(s), %d annonce(s) retirée(s) en attendant confirmation.', $bilan['rappels'], $bilan['retraits']));

        return Command::SUCCESS;
    }
}
