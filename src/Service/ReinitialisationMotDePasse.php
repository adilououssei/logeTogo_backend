<?php

namespace App\Service;

use App\Entity\CodeReinitialisation;
use App\Entity\Utilisateur;
use App\Repository\CodeReinitialisationRepository;
use App\Repository\JetonRenouvellementRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Mot de passe oublié, en trois étapes :
 *   1. demander()      : un code à 6 chiffres est envoyé à l'email du compte ;
 *   2. verifier()      : l'application contrôle le code avant d'afficher le nouveau mot de passe ;
 *   3. reinitialiser() : le mot de passe est remplacé et les autres appareils déconnectés.
 */
class ReinitialisationMotDePasse
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly CodeReinitialisationRepository $codes,
        private readonly JetonRenouvellementRepository $jetonsRenouvellement,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.demande_code')]
        private readonly RateLimiterFactoryInterface $limiteDemandes,
        #[Autowire(service: 'limiter.verification_code')]
        private readonly RateLimiterFactoryInterface $limiteVerifications,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')]
        private readonly string $expediteur,
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
    }

    /**
     * Envoie un code si le compte existe et a un email. La réponse est la même dans tous
     * les cas, pour ne pas révéler quels identifiants existent.
     */
    public function demander(string $identifiant, ?string $ip): void
    {
        $identifiant = UtilisateurRepository::normaliserIdentifiant($identifiant);
        if (!$this->limiteDemandes->create($identifiant.'-'.$ip)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop de demandes. Réessayez dans quelques minutes.');
        }

        $utilisateur = $this->utilisateurs->loadUserByIdentifier($identifiant);
        if (null === $utilisateur || null === $utilisateur->getEmail()) {
            return;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
        $this->codes->supprimerPour($utilisateur);
        $this->em->persist(new CodeReinitialisation($utilisateur, $code));
        $this->em->flush();

        $this->mailer->send($this->email($utilisateur, $code));
        if ('dev' === $this->environnement) {
            // Pratique sans serveur d'emails : le code apparaît dans var/log/dev.log.
            $this->logger->info('Code de réinitialisation pour {identifiant} : {code}', ['identifiant' => $identifiant, 'code' => $code]);
        }
    }

    public function verifier(string $identifiant, string $code, ?string $ip): Utilisateur
    {
        if (!$this->limiteVerifications->create((string) $ip)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop d\'essais. Réessayez dans quelques minutes.');
        }

        $utilisateur = $this->utilisateurs->loadUserByIdentifier($identifiant);
        $codeEnregistre = null !== $utilisateur ? $this->codes->trouverPour($utilisateur) : null;
        if (null === $codeEnregistre || $codeEnregistre->estExpire()) {
            throw $this->erreur('Code expiré ou inexistant. Demandez un nouveau code.');
        }

        if (!$codeEnregistre->correspond($code)) {
            if ($codeEnregistre->noterEssaiManque()) {
                $this->em->remove($codeEnregistre);
                $this->em->flush();
                throw $this->erreur('Trop d\'essais. Demandez un nouveau code.');
            }
            $this->em->flush();
            $restants = $codeEnregistre->getEssaisRestants();
            throw $this->erreur(\sprintf('Code incorrect. Encore %d essai%s.', $restants, $restants > 1 ? 's' : ''));
        }

        return $utilisateur;
    }

    /** Le code n'est utilisable qu'une fois ; les autres appareils sont déconnectés. */
    public function reinitialiser(string $identifiant, string $code, string $nouveauMotDePasse, ?string $ip): Utilisateur
    {
        $utilisateur = $this->verifier($identifiant, $code, $ip);

        $utilisateur->setMotDePasse($this->hacheur->hashPassword($utilisateur, $nouveauMotDePasse));
        $this->codes->supprimerPour($utilisateur);
        $this->em->flush();

        $this->jetonsRenouvellement->createQueryBuilder('j')
            ->delete()
            ->andWhere('j.username = :identifiant')
            ->setParameter('identifiant', $utilisateur->getUserIdentifier())
            ->getQuery()
            ->execute();

        return $utilisateur;
    }

    private function email(Utilisateur $utilisateur, string $code): Email
    {
        $minutes = 15;
        $texte = \sprintf(
            "Bonjour %s,\n\nVotre code de réinitialisation LogeTogo est : %s\n\nIl est valable %d minutes. Si vous n'avez rien demandé, ignorez cet email : votre mot de passe reste inchangé.\n\nL'équipe LogeTogo",
            $utilisateur->getPrenom(), $code, $minutes,
        );
        $html = \sprintf(
            '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
            .'<h2 style="color:#9A3412">LogeTogo</h2>'
            .'<p>Bonjour %s,</p>'
            .'<p>Voici votre code de réinitialisation du mot de passe :</p>'
            .'<p style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;background:#FFF7ED;padding:16px;border-radius:8px">%s</p>'
            .'<p>Il est valable <strong>%d minutes</strong>.</p>'
            .'<p style="color:#666;font-size:13px">Si vous n\'avez rien demandé, ignorez cet email : votre mot de passe reste inchangé.</p>'
            .'</div>',
            htmlspecialchars((string) $utilisateur->getPrenom()), $code, $minutes,
        );

        return (new Email())
            ->from(Address::create($this->expediteur))
            ->to(new Address((string) $utilisateur->getEmail(), trim($utilisateur->getPrenom().' '.$utilisateur->getNom())))
            ->subject(\sprintf('%s est votre code LogeTogo', $code))
            ->text($texte)
            ->html($html);
    }

    private function erreur(string $texte): ValidationFailedException
    {
        return new ValidationFailedException(null, new ConstraintViolationList([
            new ConstraintViolation($texte, $texte, [], null, 'code', null),
        ]));
    }
}
