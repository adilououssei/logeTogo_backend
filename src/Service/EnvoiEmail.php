<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Emails d'information (alertes, vérification…) aux couleurs de LogeTogo.
 * Un échec d'envoi est journalisé sans interrompre l'action en cours (publication, etc.).
 */
class EnvoiEmail
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
    ) {
    }

    /** Ne fait rien si l'utilisateur n'a pas d'email. */
    public function envoyer(Utilisateur $destinataire, string $sujet, string $texte, ?string $lien = null, ?string $libelleLien = null): void
    {
        if (null === $destinataire->getEmail()) {
            return;
        }

        $paragraphes = implode('', array_map(
            fn (string $p) => '<p>'.nl2br(htmlspecialchars($p)).'</p>',
            array_filter(explode("\n\n", $texte)),
        ));
        $bouton = null !== $lien
            ? \sprintf('<p style="text-align:center;margin:24px 0"><a href="%s" style="background:#9A3412;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">%s</a></p>', htmlspecialchars($lien), htmlspecialchars($libelleLien ?? 'Ouvrir'))
            : '';
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#222">'
            .'<h2 style="color:#9A3412">LogeTogo</h2>'
            .\sprintf('<p>Bonjour %s,</p>', htmlspecialchars((string) $destinataire->getPrenom()))
            .$paragraphes.$bouton
            .'<p style="color:#666;font-size:12px;margin-top:24px">Vous pouvez désactiver ces emails dans l\'application : Profil, Notifications.</p>'
            .'</div>';

        try {
            $this->mailer->send((new Email())
                ->from(Address::create($this->expediteur))
                ->to(new Address((string) $destinataire->getEmail(), trim($destinataire->getPrenom().' '.$destinataire->getNom())))
                ->subject($sujet)
                ->text(\sprintf("Bonjour %s,\n\n%s\n\nL'équipe LogeTogo", $destinataire->getPrenom(), $texte))
                ->html($html));
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Email non envoyé à {identifiant} : {erreur}', [
                'identifiant' => $destinataire->getUserIdentifier(),
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
