<?php

namespace App\Service;

use App\Dto\AnnonceDto;
use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Publication, modification, changement de statut et suppression des annonces. */
class GestionAnnonces
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validateur,
        private readonly StockageMedias $stockageMedias,
        private readonly Notificateur $notificateur,
    ) {
    }

    public function publier(AnnonceDto $donnees, Utilisateur $auteur): Annonce
    {
        $annonce = new Annonce();
        $this->remplir($annonce, $donnees);
        $auteur->addAnnonce($annonce);

        $this->valider($annonce);
        $this->em->persist($annonce);
        $this->em->flush();

        // Les utilisateurs dont une alerte de recherche correspond sont prévenus.
        $this->notificateur->nouvelleAnnonce($annonce);

        return $annonce;
    }

    public function modifier(Annonce $annonce, AnnonceDto $donnees): Annonce
    {
        $this->remplir($annonce, $donnees);
        $this->valider($annonce);
        $this->em->flush();

        return $annonce;
    }

    /**
     * Seul un administrateur peut suspendre une annonce ou lever une suspension.
     */
    public function changerStatut(Annonce $annonce, StatutAnnonce $statut, Utilisateur $demandeur): Annonce
    {
        $estAdmin = RoleUtilisateur::ADMIN === $demandeur->getRole();
        if (!$estAdmin && (StatutAnnonce::SUSPENDU === $statut || StatutAnnonce::SUSPENDU === $annonce->getStatut())) {
            throw new AccessDeniedHttpException('Seul un administrateur peut suspendre une annonce ou lever une suspension.');
        }

        $ancien = $annonce->getStatut();
        $annonce->setStatut($statut);
        $this->em->flush();

        // Ceux qui l'ont en favori sont prévenus d'un vrai changement (pas d'un clic sur le même statut).
        if ($ancien !== $statut) {
            $this->notificateur->changementStatut($annonce);
        }

        return $annonce;
    }

    /** Supprime l'annonce, ses photos et vidéos (fichiers compris). */
    public function supprimer(Annonce $annonce): void
    {
        $this->stockageMedias->supprimerDossierAnnonce($annonce);
        $this->em->remove($annonce);
        $this->em->flush();
    }

    private function remplir(Annonce $annonce, AnnonceDto $d): void
    {
        $location = TypeTransaction::LOCATION === $d->typeTransaction;

        $annonce->setTitre(trim($d->titre))
            ->setDescription(trim($d->description))
            ->setTypeBien($d->typeBien)
            ->setTypeTransaction($d->typeTransaction)
            ->setPrix($d->prix)
            // Avance, caution et commission n'ont de sens que pour une location.
            ->setAvanceMois($location ? $d->avanceMois : null)
            ->setCautionMois($location ? $d->cautionMois : null)
            ->setCommission($location ? $d->commission : null)
            ->setChambres($d->chambres)
            ->setSallesDeBain($d->sallesDeBain)
            ->setSuperficie($d->superficie)
            ->setEquipements(array_map('trim', $d->equipements));

        $annonce->getLocalisation()
            ->setRegion($d->region)
            ->setVille(trim($d->ville))
            ->setQuartier(trim($d->quartier))
            ->setAdresse(null !== $d->adresse ? trim($d->adresse) : null)
            ->setLatitude($d->latitude)
            ->setLongitude($d->longitude);

        $annonce->getContact()
            ->setTelephone(null !== $d->telephoneContact ? trim($d->telephoneContact) : null)
            ->setWhatsapp(null !== $d->whatsappContact ? trim($d->whatsappContact) : null);
    }

    private function valider(Annonce $annonce): void
    {
        $violations = $this->validateur->validate($annonce);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($annonce, $violations);
        }
    }
}
