<?php

namespace App\Service;

use App\Entity\Annonce;
use App\Entity\Notification;
use App\Enum\MotifSignalement;
use App\Enum\StatutAnnonce;
use App\Enum\TypeNotification;
use App\Enum\TypeTransaction;
use App\Repository\AnnonceRepository;
use App\Repository\SignalementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Garde le catalogue à jour : un bien loué ou vendu ne doit plus être proposé aux locataires,
 * même si l'annonceur oublie de le signaler.
 *
 * - 7 jours après la publication (ou la dernière confirmation), l'annonceur reçoit un rappel :
 *   « Ce bien est-il toujours disponible ? » (notification, push et email avec deux boutons) ;
 * - sans réponse 7 jours plus tard, l'annonce passe « À confirmer » : retirée du public,
 *   elle revient en ligne dès que l'annonceur confirme ;
 * - plusieurs locataires signalent le bien comme déjà loué : même retrait, sans attendre.
 *
 * Le passage des jours est vérifié une fois par jour par la commande app:annonces:suivi-disponibilite.
 */
class SuiviDisponibilite
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnnonceRepository $annonces,
        private readonly SignalementRepository $signalements,
        private readonly Notificateur $notificateur,
        private readonly EnvoiPush $push,
        private readonly EnvoiEmail $email,
        private readonly UrlGeneratorInterface $urls,
        private readonly UriSigner $signataire,
        #[Autowire('%env(int:DISPONIBILITE_RAPPEL_JOURS)%')]
        private readonly int $joursAvantRappel,
        #[Autowire('%env(int:DISPONIBILITE_RETRAIT_JOURS)%')]
        private readonly int $joursAvantRetrait,
        #[Autowire('%env(int:DISPONIBILITE_SIGNALEMENTS)%')]
        private readonly int $signalementsAvantRetrait,
    ) {
    }

    /** L'annonceur confirme : le bien reste (ou revient) en ligne et le délai repart de zéro. */
    public function confirmer(Annonce $annonce): void
    {
        $revient = StatutAnnonce::DISPONIBLE !== $annonce->getStatut();
        $annonce->confirmerDisponibilite();
        // Les signalements « déjà loué » antérieurs sont dépassés par cette confirmation.
        foreach ($this->signalements->enAttentePour($annonce) as $signalement) {
            if (MotifSignalement::DEJA_LOUE === $signalement->getMotif()) {
                $signalement->cloturer(false);
            }
        }
        $this->em->flush();
        if ($revient) {
            $this->notificateur->changementStatut($annonce);
        }
    }

    /** L'annonceur indique que le bien est pris : loué (location) ou vendu (vente). */
    public function marquerPris(Annonce $annonce): void
    {
        $statut = TypeTransaction::VENTE === $annonce->getTypeTransaction() ? StatutAnnonce::VENDU : StatutAnnonce::OCCUPE;
        $change = $statut !== $annonce->getStatut();
        $annonce->setStatut($statut)->setDateRappelDisponibilite(null);
        $this->em->flush();
        if ($change) {
            $this->notificateur->changementStatut($annonce);
        }
    }

    /**
     * Rappels et retraits du jour.
     *
     * @return array{rappels: int, retraits: int}
     */
    public function verifier(\DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $rappels = $this->annonces->sansConfirmationDepuis($maintenant->modify(\sprintf('-%d days', $this->joursAvantRappel)));
        foreach ($rappels as $annonce) {
            $annonce->setDateRappelDisponibilite($maintenant);
            $this->rappeler($annonce);
        }

        $retraits = $this->annonces->rappelSansReponseDepuis($maintenant->modify(\sprintf('-%d days', $this->joursAvantRetrait)));
        foreach ($retraits as $annonce) {
            $this->retirer($annonce, \sprintf(
                'Sans réponse de votre part depuis %d jours, « %s » n\'est plus visible des locataires. Touchez « Toujours disponible » pour la remettre en ligne.',
                $this->joursAvantRetrait,
                $annonce->getTitre(),
            ));
        }
        $this->em->flush();

        return ['rappels' => \count($rappels), 'retraits' => \count($retraits)];
    }

    /** Un locataire signale le bien comme déjà loué : à partir de plusieurs signalements, il est retiré. */
    public function signaleDejaLoue(Annonce $annonce): void
    {
        if (StatutAnnonce::DISPONIBLE !== $annonce->getStatut()) {
            return;
        }
        $signalements = array_filter(
            $this->signalements->enAttentePour($annonce),
            fn ($s) => MotifSignalement::DEJA_LOUE === $s->getMotif() && $s->getDateCreation() >= $annonce->getDateConfirmation(),
        );
        if (\count($signalements) < $this->signalementsAvantRetrait) {
            return;
        }
        $this->retirer($annonce, \sprintf(
            'Plusieurs personnes indiquent que « %s » est déjà %s. L\'annonce est retirée en attendant votre confirmation.',
            $annonce->getTitre(),
            TypeTransaction::VENTE === $annonce->getTypeTransaction() ? 'vendu' : 'loué',
        ));
        $this->em->flush();
    }

    /**
     * Lien de l'email : confirme ou marque pris sans connexion à l'application.
     * Signé et valable jusqu'au retrait de l'annonce (après, il faut passer par l'application).
     */
    public function lienEmail(Annonce $annonce, string $reponse): string
    {
        $url = $this->urls->generate('disponibilite_repondre', ['id' => $annonce->getId(), 'reponse' => $reponse], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signataire->sign($url, new \DateTimeImmutable(\sprintf('+%d days', $this->joursAvantRetrait + 1)));
    }

    private function rappeler(Annonce $annonce): void
    {
        $agent = $annonce->getPubliePar();
        if (null === $agent) {
            return;
        }
        $vente = TypeTransaction::VENTE === $annonce->getTypeTransaction();
        $titre = 'Votre bien est-il toujours disponible ?';
        $corps = \sprintf(
            '« %s » est en ligne depuis %d jours. Confirmez qu\'il est toujours disponible, ou indiquez qu\'il est déjà %s : sans réponse sous %d jours, il sera retiré.',
            $annonce->getTitre(),
            $this->joursAvantRappel,
            $vente ? 'vendu' : 'loué',
            $this->joursAvantRetrait,
        );
        $this->notifier($annonce, $titre, $corps);

        $this->email->envoyer(
            $agent,
            $titre.' · LogeTogo',
            $corps."\n\nToujours disponible : ".$this->lienEmail($annonce, 'disponible')
                ."\n\nDéjà ".($vente ? 'vendu' : 'loué').' : '.$this->lienEmail($annonce, 'pris'),
            $this->lienEmail($annonce, 'disponible'),
            'Oui, toujours disponible',
        );
    }

    private function retirer(Annonce $annonce, string $explication): void
    {
        $annonce->setStatut(StatutAnnonce::A_CONFIRMER)->setDateRappelDisponibilite(null);
        $this->notifier($annonce, 'Annonce retirée en attendant votre confirmation', $explication);
        // Ceux qui l'avaient en favori apprennent qu'elle n'est plus disponible.
        $this->notificateur->changementStatut($annonce);
    }

    private function notifier(Annonce $annonce, string $titre, string $corps): void
    {
        $agent = $annonce->getPubliePar();
        if (null === $agent) {
            return;
        }
        $this->em->persist((new Notification())
            ->setDestinataire($agent)
            ->setType(TypeNotification::DISPONIBILITE)
            ->setTitre($titre)
            ->setContenu($corps)
            ->setAnnonce($annonce));
        $this->em->flush();
        $this->push->envoyer([$agent], $titre, $corps, ['type' => TypeNotification::DISPONIBILITE->value, 'idAnnonce' => $annonce->getId()]);
    }
}
