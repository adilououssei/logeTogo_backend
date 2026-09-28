<?php

namespace App\Tests\Controller;

use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnnonceControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testListePubliqueSansCoordonneesNiContacts(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $this->creerAnnonce($agent);

        $reponse = $this->requeteJson('GET', '/api/annonces');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $reponse['total']);
        $annonce = $reponse['elements'][0];
        self::assertSame('Chambre meublée à Adidogomé', $annonce['titre']);
        self::assertSame('chambre', $annonce['typeBien']);
        self::assertSame(['region' => 'maritime', 'ville' => 'Lomé', 'quartier' => 'Adidogomé'], $annonce['localisation']);
        self::assertSame(175000, $annonce['totalEntree']);
        self::assertSame('Kofi', $annonce['publiePar']['prenom']);
        self::assertArrayNotHasKey('telephone', $annonce['publiePar']);
        self::assertArrayNotHasKey('email', $annonce['publiePar']);
        self::assertArrayNotHasKey('contact', $annonce);
    }

    public function testListeNeMontreQueLesAnnoncesDisponiblesParDefaut(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $this->creerAnnonce($agent, ['titre' => 'Disponible']);
        $this->creerAnnonce($agent, ['titre' => 'Occupée', 'statut' => StatutAnnonce::OCCUPE]);
        $this->creerAnnonce($agent, ['titre' => 'Suspendue', 'statut' => StatutAnnonce::SUSPENDU]);

        self::assertSame(['Disponible'], array_column($this->requeteJson('GET', '/api/annonces')['elements'], 'titre'));
        self::assertSame(['Occupée'], array_column($this->requeteJson('GET', '/api/annonces?statut=occupe')['elements'], 'titre'));

        $this->requeteJson('GET', '/api/annonces?statut=suspendu');
        self::assertResponseStatusCodeSame(422);
    }

    public function testFiltres(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $this->creerAnnonce($agent, ['titre' => 'Chambre Lomé', 'prix' => 25000]);
        $this->creerAnnonce($agent, ['titre' => 'Studio Lomé', 'prix' => 60000, 'typeBien' => TypeBien::STUDIO]);
        $this->creerAnnonce($agent, ['titre' => 'Terrain Kara', 'prix' => 9000000, 'region' => Region::KARA, 'ville' => 'Kara', 'quartier' => 'Tomdè', 'typeBien' => TypeBien::TERRAIN, 'typeTransaction' => TypeTransaction::VENTE]);

        $titres = fn (string $requete) => array_column($this->requeteJson('GET', '/api/annonces'.$requete)['elements'], 'titre');

        self::assertSame(['Terrain Kara'], $titres('?region=kara'));
        self::assertSame(['Studio Lomé'], $titres('?typeBien=studio'));
        self::assertSame(['Terrain Kara'], $titres('?typeTransaction=vente'));
        self::assertEqualsCanonicalizing(['Chambre Lomé', 'Studio Lomé'], $titres('?prixMax=100000'));
        self::assertSame(['Terrain Kara'], $titres('?recherche=tomd'));
        self::assertSame(['Chambre Lomé'], $titres('?quartier=Adidogomé&typeBien=chambre'));
    }

    public function testRegionPrioritairePuisPlusRecentes(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $this->creerAnnonce($agent, ['titre' => 'Lomé ancienne', 'datePublication' => '2026-01-01 10:00:00']);
        $this->creerAnnonce($agent, ['titre' => 'Kara', 'region' => Region::KARA, 'datePublication' => '2026-01-02 10:00:00']);
        $this->creerAnnonce($agent, ['titre' => 'Lomé récente', 'datePublication' => '2026-01-03 10:00:00']);

        $titres = fn (string $requete) => array_column($this->requeteJson('GET', '/api/annonces'.$requete)['elements'], 'titre');

        self::assertSame(['Lomé récente', 'Kara', 'Lomé ancienne'], $titres(''));
        self::assertSame(['Kara', 'Lomé récente', 'Lomé ancienne'], $titres('?regionPrioritaire=kara'));
    }

    public function testPagination(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        for ($i = 1; $i <= 5; ++$i) {
            $this->creerAnnonce($agent, ['titre' => "Annonce $i", 'datePublication' => "2026-01-0$i 10:00:00"]);
        }

        $page2 = $this->requeteJson('GET', '/api/annonces?parPage=2&page=2');

        self::assertSame(5, $page2['total']);
        self::assertSame(3, $page2['pages']);
        self::assertSame(['Annonce 3', 'Annonce 2'], array_column($page2['elements'], 'titre'));
    }

    public function testDetailVisiteurSansGpsEtConnecteAvecGps(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $locataire = $this->creerUtilisateur('ama.koffi');

        $visiteur = $this->requeteJson('GET', '/api/annonces/'.$annonce->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('Description de test', $visiteur['description']);
        self::assertArrayNotHasKey('latitude', $visiteur['localisation']);
        self::assertArrayNotHasKey('adresse', $visiteur['localisation']);
        self::assertArrayNotHasKey('contact', $visiteur);
        self::assertArrayNotHasKey('telephone', $visiteur['publiePar']);
        self::assertArrayNotHasKey('whatsapp', $visiteur['publiePar']);

        $agent->setWhatsapp('99 11 22 33');
        $this->em()->flush();
        $connecte = $this->requeteJson('GET', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($locataire));
        self::assertSame(6.1726, $connecte['localisation']['latitude']);
        self::assertSame('Près du marché', $connecte['localisation']['adresse']);
        self::assertSame('+228 91 11 11 11', $connecte['contact']['telephone']);
        self::assertSame('+228 90 00 00 01', $connecte['publiePar']['telephone']);
        self::assertSame('99 11 22 33', $connecte['publiePar']['whatsapp']);
    }

    public function testConsultationCompteUneVueSaufPourLAuteur(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $this->requeteJson('GET', '/api/annonces/'.$annonce->getId());
        $this->requeteJson('GET', '/api/annonces/'.$annonce->getId());
        $parAuteur = $this->requeteJson('GET', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($agent));

        self::assertSame(2, $parAuteur['nombreVues']);
    }

    public function testAnnonceSuspendueInvisiblePourLePublic(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent, ['statut' => StatutAnnonce::SUSPENDU]);

        $this->requeteJson('GET', '/api/annonces/'.$annonce->getId());
        self::assertResponseStatusCodeSame(404);

        $this->requeteJson('GET', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($agent));
        self::assertResponseIsSuccessful();

        $this->requeteJson('GET', '/api/annonces/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAgentPublieUneAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);

        $reponse = $this->requeteJson('POST', '/api/annonces', $this->donneesAnnonce(), $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Studio moderne à Bè', $reponse['titre']);
        self::assertSame('disponible', $reponse['statut']);
        self::assertSame('kofi.mensah@exemple.tg', $reponse['publiePar']['email']);
        self::assertSame(['Eau courante', 'Électricité'], $reponse['equipements']);
        self::assertSame(6.14, $reponse['localisation']['latitude']);
        self::assertSame(185000, $reponse['totalEntree']);
    }

    public function testVenteIgnoreAvanceCautionEtCommission(): void
    {
        $proprietaire = $this->creerUtilisateur('edem.agbo', RoleUtilisateur::PROPRIETAIRE);

        $reponse = $this->requeteJson('POST', '/api/annonces', ['typeTransaction' => 'vente', 'typeBien' => 'terrain', 'prix' => 8500000] + $this->donneesAnnonce(), $this->jetonPour($proprietaire));

        self::assertResponseStatusCodeSame(201);
        self::assertNull($reponse['avanceMois']);
        self::assertNull($reponse['commission']);
        self::assertNull($reponse['totalEntree']);
    }

    public function testLocataireEtVisiteurNePeuventPasPublier(): void
    {
        $locataire = $this->creerUtilisateur('ama.koffi');

        $this->requeteJson('POST', '/api/annonces', $this->donneesAnnonce(), $this->jetonPour($locataire));
        self::assertResponseStatusCodeSame(403);

        $visiteur = $this->requeteJson('POST', '/api/annonces', $this->donneesAnnonce());
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Vous devez être connecté.', $visiteur['message']);
    }

    public function testPublicationRefuseLesDonneesInvalides(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);

        $reponse = $this->requeteJson('POST', '/api/annonces', ['titre' => '', 'prix' => -5, 'region' => 'maritime'], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
        $champs = array_column($reponse['violations'], 'propertyPath');
        foreach (['titre', 'prix', 'typeBien', 'typeTransaction', 'ville', 'quartier', 'description'] as $champ) {
            self::assertContains($champ, $champs);
        }
    }

    public function testSeulLAuteurModifieEtSupprime(): void
    {
        $auteur = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $autreAgent = $this->creerUtilisateur('edem.agbo', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($auteur);
        $url = '/api/annonces/'.$annonce->getId();

        $this->requeteJson('PUT', $url, $this->donneesAnnonce(), $this->jetonPour($autreAgent));
        self::assertResponseStatusCodeSame(403);
        $this->requeteJson('DELETE', $url, jeton: $this->jetonPour($autreAgent));
        self::assertResponseStatusCodeSame(403);

        $modifiee = $this->requeteJson('PUT', $url, ['titre' => 'Titre modifié'] + $this->donneesAnnonce(), $this->jetonPour($auteur));
        self::assertResponseIsSuccessful();
        self::assertSame('Titre modifié', $modifiee['titre']);
        self::assertNotNull($modifiee['dateModification']);

        $this->requeteJson('DELETE', $url, jeton: $this->jetonPour($auteur));
        self::assertResponseStatusCodeSame(204);
        $this->requeteJson('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangementDeStatut(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $admin = $this->creerUtilisateur('admin.logetogo', RoleUtilisateur::ADMIN);
        $annonce = $this->creerAnnonce($agent);
        $url = '/api/annonces/'.$annonce->getId().'/statut';

        self::assertSame('occupe', $this->requeteJson('PATCH', $url, ['statut' => 'occupe'], $this->jetonPour($agent))['statut']);

        $this->requeteJson('PATCH', $url, ['statut' => 'suspendu'], $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(403);

        self::assertSame('suspendu', $this->requeteJson('PATCH', $url, ['statut' => 'suspendu'], $this->jetonPour($admin))['statut']);

        $this->requeteJson('PATCH', $url, ['statut' => 'disponible'], $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(403, 'l\'agent ne peut pas lever une suspension');
    }

    public function testMesAnnoncesTousStatuts(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $autre = $this->creerUtilisateur('edem.agbo', RoleUtilisateur::AGENT);
        $this->creerAnnonce($agent, ['titre' => 'A', 'datePublication' => '2026-01-01 10:00:00']);
        $this->creerAnnonce($agent, ['titre' => 'B', 'statut' => StatutAnnonce::OCCUPE, 'datePublication' => '2026-01-02 10:00:00']);
        $this->creerAnnonce($autre, ['titre' => 'Autre']);

        $reponse = $this->requeteJson('GET', '/api/annonces/mes-annonces', jeton: $this->jetonPour($agent));

        self::assertResponseIsSuccessful();
        self::assertSame(['B', 'A'], array_column($reponse, 'titre'));

        $this->requeteJson('GET', '/api/annonces/mes-annonces', jeton: $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function donneesAnnonce(): array
    {
        return [
            'titre' => 'Studio moderne à Bè', 'description' => 'Studio climatisé, cuisine équipée.',
            'typeBien' => 'studio', 'typeTransaction' => 'location', 'prix' => 45000,
            'avanceMois' => 3, 'cautionMois' => 2, 'commission' => 50000, 'chambres' => 1, 'sallesDeBain' => 1,
            'superficie' => 35, 'equipements' => ['Eau courante', 'Électricité', 'Eau courante'],
            'region' => 'maritime', 'ville' => 'Lomé', 'quartier' => 'Bè', 'adresse' => 'Rue 12',
            'latitude' => 6.14, 'longitude' => 1.245, 'telephoneContact' => '+228 92 22 22 22',
        ];
    }
}
