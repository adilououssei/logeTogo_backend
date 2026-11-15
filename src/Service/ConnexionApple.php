<?php

namespace App\Service;

use App\Dto\ConnexionAppleDto;
use App\Dto\InscriptionDto;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Security\VerificateurCompte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * « Se connecter avec Apple » (même parcours que « Continuer avec Google ») :
 *  1. compte déjà lié à cet Apple → connexion ;
 *  2. compte existant avec le même email (vérifié par Apple) → lié, puis connexion ;
 *  3. sinon, nouveau compte : il faut d'abord la région (écran « Compléter votre profil »).
 *     Sans région, l'identité Apple est renvoyée ; le nom vient de l'application (Apple ne le met pas dans le jeton).
 */
class ConnexionApple
{
    public function __construct(
        private readonly VerificateurJetonApple $verificateur,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InscriptionUtilisateur $inscription,
        private readonly VerificateurCompte $verificateurCompte,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return Utilisateur|IdentiteApple le compte connecté, ou l'identité à compléter */
    public function connecter(ConnexionAppleDto $donnees): Utilisateur|IdentiteApple
    {
        $identite = $this->verificateur->verifier($donnees->jeton);

        $utilisateur = $this->utilisateurs->findOneBy(['appleId' => $identite->appleId])
            ?? (null !== $identite->email ? $this->utilisateurs->findOneBy(['email' => $identite->email]) : null);

        if (null !== $utilisateur) {
            try {
                $this->verificateurCompte->checkPreAuth($utilisateur);
            } catch (AccountStatusException $e) {
                // Sans l'exception d'origine : la sécurité la prendrait pour un « non connecté » (401).
                throw new AccessDeniedHttpException($e->getMessageKey());
            }
            if (null === $utilisateur->getAppleId()) {
                $utilisateur->setAppleId($identite->appleId);
                $this->em->flush();
            }

            return $utilisateur;
        }

        if (null === $donnees->region) {
            return $identite;
        }

        // Pas de mot de passe choisi : un mot de passe aléatoire, que personne ne connaît.
        // L'utilisateur pourra en définir un avec « Mot de passe oublié » (code envoyé à son email).
        return $this->inscription->inscrire(new InscriptionDto(
            prenom: trim($donnees->prenom ?? ''),
            nom: trim($donnees->nom ?? ''),
            region: $donnees->region,
            motDePasse: bin2hex(random_bytes(32)),
            email: $identite->email,
            telephone: $donnees->telephone,
            indicatifPays: $donnees->indicatifPays,
            whatsapp: $donnees->whatsapp,
        ), appleId: $identite->appleId);
    }
}
