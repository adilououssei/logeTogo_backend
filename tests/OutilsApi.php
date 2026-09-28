<?php

namespace App\Tests;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Outils communs aux tests fonctionnels de l'API. */
trait OutilsApi
{
    private KernelBrowser $client;

    private function viderBase(): void
    {
        $connexion = $this->em()->getConnection();
        foreach (['signalement', 'demande_verification', 'code_reinitialisation', 'media', 'favori', 'avis', 'notification', 'message', 'conversation', 'annonce', 'jeton_renouvellement', 'utilisateur'] as $table) {
            $connexion->executeStatement("DELETE FROM $table");
        }
        // Compteurs de tentatives de connexion : chaque test repart de zéro.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function creerUtilisateur(string $identifiant, RoleUtilisateur $role = RoleUtilisateur::LOCATAIRE, Region $region = Region::MARITIME): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setIdentifiant($identifiant)
            ->setPrenom(ucfirst(strtok($identifiant, '.')))
            ->setNom(ucfirst((string) strtok('.')))
            ->setRegion($region)
            ->setRole($role)
            ->setTelephone('+228 90 00 00 01')
            ->setEmail($identifiant.'@exemple.tg')
            ->setMotDePasse('non-utilise');
        $this->em()->persist($utilisateur);
        $this->em()->flush();

        return $utilisateur;
    }

    private function jetonPour(Utilisateur $utilisateur): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($utilisateur);
    }

    /** @param array<string, mixed> $modifications */
    private function creerAnnonce(Utilisateur $auteur, array $modifications = []): Annonce
    {
        $d = $modifications + [
            'titre' => 'Chambre meublée à Adidogomé', 'prix' => 25000, 'region' => Region::MARITIME,
            'ville' => 'Lomé', 'quartier' => 'Adidogomé', 'typeBien' => TypeBien::CHAMBRE,
            'typeTransaction' => TypeTransaction::LOCATION, 'datePublication' => null,
        ];
        $annonce = (new Annonce())
            ->setTitre($d['titre'])->setDescription('Description de test')
            ->setTypeBien($d['typeBien'])->setTypeTransaction($d['typeTransaction'])
            ->setPrix($d['prix'])->setAvanceMois(6)->setCommission(25000);
        $annonce->getLocalisation()->setRegion($d['region'])->setVille($d['ville'])->setQuartier($d['quartier'])
            ->setAdresse('Près du marché')->setLatitude(6.1726)->setLongitude(1.2312);
        $annonce->getContact()->setTelephone('+228 91 11 11 11');
        if (isset($d['statut'])) {
            $annonce->setStatut($d['statut']);
        }
        $auteur->addAnnonce($annonce);
        $this->em()->persist($annonce);
        $this->em()->flush();
        if (null !== $d['datePublication']) {
            $this->em()->getConnection()->executeStatement('UPDATE annonce SET date_publication = ? WHERE id = ?', [$d['datePublication'], $annonce->getId()]);
        }

        return $annonce;
    }

    /** @param array<string, mixed>|null $corps */
    private function requeteJson(string $methode, string $url, ?array $corps = null, ?string $jeton = null): array
    {
        $entetes = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $jeton) {
            $entetes['HTTP_AUTHORIZATION'] = 'Bearer '.$jeton;
        }
        $this->client->request($methode, $url, server: $entetes, content: null !== $corps ? json_encode($corps) : null);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
