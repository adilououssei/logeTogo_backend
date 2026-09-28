<?php

namespace App\Command;

use App\Entity\DemandeVerification;
use App\Repository\DemandeVerificationRepository;
use App\Service\VerificationIdentite;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Traitement des vérifications d'identité par un administrateur :
 *   php bin/console app:verifications                       liste des demandes en attente
 *   php bin/console app:verifications 12 --approuver        accorde le badge « vérifié »
 *   php bin/console app:verifications 12 --refuser="Photo illisible"
 */
#[AsCommand(name: 'app:verifications', description: 'Liste et traite les demandes de vérification d\'identité')]
class VerificationsCommand
{
    public function __construct(
        private readonly DemandeVerificationRepository $demandes,
        private readonly VerificationIdentite $verification,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Numéro de la demande à traiter')] ?int $id = null,
        #[Option('Accorder le badge « vérifié »')] bool $approuver = false,
        #[Option('Refuser la demande, avec le motif montré à l\'utilisateur')] ?string $refuser = null,
    ): int {
        if (null === $id) {
            return $this->lister($io);
        }

        $demande = $this->demandes->find($id);
        if (null === $demande) {
            $io->error(\sprintf('Aucune demande n°%d.', $id));

            return Command::FAILURE;
        }
        if (!$demande->estEnAttente()) {
            $io->warning('Cette demande a déjà été traitée.');

            return Command::FAILURE;
        }
        if ($approuver === (null !== $refuser)) {
            $io->error('Indiquez --approuver ou --refuser="motif".');

            return Command::INVALID;
        }

        $this->verification->decider($demande, $approuver, $refuser);
        $io->success(\sprintf('Demande n°%d %s ; l\'utilisateur a été prévenu et les photos supprimées.', $id, $approuver ? 'approuvée' : 'refusée'));

        return Command::SUCCESS;
    }

    private function lister(SymfonyStyle $io): int
    {
        $enAttente = $this->demandes->enAttente();
        if ([] === $enAttente) {
            $io->success('Aucune demande en attente.');

            return Command::SUCCESS;
        }

        $io->table(
            ['N°', 'Utilisateur', 'Rôle', 'Pièce', 'Reçue le', 'Photos'],
            array_map(fn (DemandeVerification $d) => [
                $d->getId(),
                \sprintf('%s (%s)', $d->getUtilisateur()->getNomComplet(), $d->getUtilisateur()->getUserIdentifier()),
                $d->getUtilisateur()->getRole()->value,
                DemandeVerification::TYPES_DOCUMENT[$d->getTypeDocument()] ?? $d->getTypeDocument(),
                $d->getDateDemande()->format('d/m/Y H:i'),
                implode("\n", array_map($this->verification->cheminAbsolu(...), array_filter([$d->getRecto(), $d->getVerso()]))),
            ], $enAttente),
        );
        $io->text('Traiter : php bin/console app:verifications <n°> --approuver  |  --refuser="motif"');

        return Command::SUCCESS;
    }
}
