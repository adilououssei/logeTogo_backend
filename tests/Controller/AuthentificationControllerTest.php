<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours d'authentification tel que l'application mobile l'utilisera :
 * inscription, connexion par identifiant, accès au profil avec le jeton JWT.
 */
final class AuthentificationControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testInscriptionEtConnexionFournissentUnJetonDeRenouvellement(): void
    {
        $inscription = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);
        self::assertResponseStatusCodeSame(201);
        self::assertNotEmpty($inscription['refresh_token']);
        self::assertSame('kofi.mensah', $inscription['utilisateur']['identifiant']);

        $connexion = $this->connecter('kofi.mensah', 'secret123');
        self::assertNotEmpty($connexion['refresh_token']);
        self::assertNotSame($inscription['refresh_token'], $connexion['refresh_token'], 'un jeton par appareil / connexion');

        // Seule l'empreinte est en base, pas le jeton lui-même.
        $stockes = $this->em()->getConnection()->fetchFirstColumn('SELECT refresh_token FROM jeton_renouvellement');
        self::assertCount(2, $stockes);
        self::assertNotContains($connexion['refresh_token'], $stockes);
    }

    public function testRenouvellementDonneUnNouveauJetonSansMotDePasse(): void
    {
        $session = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);

        $renouvele = $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $session['refresh_token']]);

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($renouvele['token']);
        self::assertSame('kofi.mensah', $renouvele['utilisateur']['identifiant']);
        // Rotation : un nouveau jeton de renouvellement remplace l'ancien…
        self::assertNotSame($session['refresh_token'], $renouvele['refresh_token']);
        $this->requeteJson('GET', '/api/auth/moi', jeton: $renouvele['token']);
        self::assertResponseIsSuccessful();

        // … et l'ancien ne fonctionne plus.
        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $session['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRenouvellementRefuseAvecUnJetonInconnuOuAbsent(): void
    {
        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => 'inconnu']);
        self::assertResponseStatusCodeSame(401);

        $this->requeteJson('POST', '/api/auth/renouveler', []);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeconnexionInvalideLeJetonDeRenouvellement(): void
    {
        $session = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);

        $deconnexion = $this->requeteJson('POST', '/api/auth/deconnexion', ['refresh_token' => $session['refresh_token']], $session['token']);
        self::assertResponseIsSuccessful();
        self::assertSame('Vous êtes déconnecté.', $deconnexion['message']);

        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $session['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCompteSuspenduNePeutPlusRenouvelerSaSession(): void
    {
        $session = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);
        $this->em()->getConnection()->executeStatement("UPDATE utilisateur SET est_actif = 0 WHERE identifiant = 'kofi.mensah'");

        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $session['refresh_token']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testInscriptionCreeLeCompteEtRenvoieUnJeton(): void
    {
        $reponse = $this->inscrire(['prenom' => 'Élodie', 'nom' => 'Adjo-Mensah', 'region' => 'plateaux', 'motDePasse' => 'secret123']);

        self::assertResponseStatusCodeSame(201);
        self::assertNotEmpty($reponse['token']);
        self::assertSame('elodie.adjomensah', $reponse['utilisateur']['identifiant']);
        self::assertSame('plateaux', $reponse['utilisateur']['region']);
        self::assertSame('locataire', $reponse['utilisateur']['role']);
        self::assertNull($reponse['utilisateur']['email']);
        self::assertArrayNotHasKey('motDePasse', $reponse['utilisateur']);
        self::assertArrayNotHasKey('password', $reponse['utilisateur']);

        // Le jeton reçu à l'inscription donne accès au profil.
        $profil = $this->requeteJson('GET', '/api/auth/moi', jeton: $reponse['token']);
        self::assertResponseIsSuccessful();
        self::assertSame('elodie.adjomensah', $profil['identifiant']);

        // Le mot de passe est stocké haché.
        $enBase = $this->em()->getRepository(Utilisateur::class)->findOneBy(['identifiant' => 'elodie.adjomensah']);
        self::assertNotSame('secret123', $enBase->getMotDePasse());
    }

    public function testDeuxHomonymesObtiennentDesIdentifiantsDifferents(): void
    {
        $premier = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);
        $second = $this->inscrire(['prenom' => 'KOFI', 'nom' => 'Mensah', 'region' => 'kara', 'motDePasse' => 'secret456']);

        self::assertSame('kofi.mensah', $premier['utilisateur']['identifiant']);
        self::assertSame('kofi.mensah2', $second['utilisateur']['identifiant']);
    }

    public function testInscriptionAvecChampsOptionnels(): void
    {
        $reponse = $this->inscrire([
            'prenom' => 'Ama', 'nom' => 'Koffi', 'region' => 'centrale', 'motDePasse' => 'secret123',
            'role' => 'agent', 'email' => 'Ama@Exemple.tg', 'telephone' => '90 00 00 02', 'indicatifPays' => '+228',
            'whatsapp' => '99 00 00 02',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('agent', $reponse['utilisateur']['role']);
        self::assertSame('ama@exemple.tg', $reponse['utilisateur']['email']);
        self::assertSame('+228', $reponse['utilisateur']['indicatifPays']);
        self::assertSame('99 00 00 02', $reponse['utilisateur']['whatsapp']);
    }

    public function testWhatsappSeulGardeLIndicatif(): void
    {
        $reponse = $this->inscrire([
            'prenom' => 'Edem', 'nom' => 'Afi', 'region' => 'kara', 'motDePasse' => 'secret123',
            'whatsapp' => '97 00 00 03', 'indicatifPays' => '+228',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($reponse['utilisateur']['telephone']);
        self::assertSame('97 00 00 03', $reponse['utilisateur']['whatsapp']);
        self::assertSame('+228', $reponse['utilisateur']['indicatifPays']);

        $this->inscrire(['prenom' => 'X', 'nom' => 'Y', 'region' => 'kara', 'motDePasse' => 'secret123', 'whatsapp' => 'abc']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testInscriptionRefuseLesDonneesInvalides(): void
    {
        $reponse = $this->inscrire(['prenom' => '', 'nom' => 'Mensah', 'motDePasse' => '123']);

        self::assertResponseStatusCodeSame(422);
        $champs = array_column($reponse['violations'], 'propertyPath');
        self::assertContains('prenom', $champs);
        self::assertContains('region', $champs);
        self::assertContains('motDePasse', $champs);
        self::assertContains('Le prénom est requis.', array_column($reponse['violations'], 'title'));
    }

    public function testInscriptionRefuseUneRegionInconnue(): void
    {
        $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'lome', 'motDePasse' => 'secret123']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOnNePeutPasSInscrireAdministrateur(): void
    {
        $reponse = $this->inscrire(['prenom' => 'Pirate', 'nom' => 'Admin', 'region' => 'maritime', 'motDePasse' => 'secret123', 'role' => 'admin']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('role', array_column($reponse['violations'], 'propertyPath'));
    }

    public function testEmailDejaUtiliseRefuse(): void
    {
        $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123', 'email' => 'kofi@exemple.tg']);
        $reponse = $this->inscrire(['prenom' => 'Edem', 'nom' => 'Agbo', 'region' => 'maritime', 'motDePasse' => 'secret123', 'email' => 'KOFI@exemple.tg']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('Cet email est déjà utilisé.', array_column($reponse['violations'], 'title'));
    }

    public function testConnexionParIdentifiantToleranteAuxMajusculesEtEspaces(): void
    {
        $this->inscrire(['prenom' => 'Élodie', 'nom' => 'Adjo-Mensah', 'region' => 'plateaux', 'motDePasse' => 'secret123']);

        $reponse = $this->connecter('  Elodie.AdjoMensah ', 'secret123');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($reponse['token']);
        self::assertSame('elodie.adjomensah', $reponse['utilisateur']['identifiant']);
        self::assertSame('plateaux', $reponse['utilisateur']['region']);

        $this->requeteJson('GET', '/api/auth/moi', jeton: $reponse['token']);
        self::assertResponseIsSuccessful();
    }

    public function testConnexionRefuseeAvecLeMemeMessageSiMotDePasseOuIdentifiantFaux(): void
    {
        $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);

        $mauvaisMotDePasse = $this->connecter('kofi.mensah', 'faux-mot-de-passe');
        self::assertResponseStatusCodeSame(401);
        $identifiantInconnu = $this->connecter('personne.inconnue', 'secret123');
        self::assertResponseStatusCodeSame(401);

        self::assertSame('Identifiant ou mot de passe incorrect.', $mauvaisMotDePasse['message']);
        self::assertSame($mauvaisMotDePasse['message'], $identifiantInconnu['message']);
    }

    public function testCompteSuspenduNePeutPlusSeConnecterNiUtiliserSonJeton(): void
    {
        $jeton = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123'])['token'];
        $this->em()->getConnection()->executeStatement("UPDATE utilisateur SET est_actif = 0 WHERE identifiant = 'kofi.mensah'");

        $connexion = $this->connecter('kofi.mensah', 'secret123');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('suspendu', $connexion['message']);

        $this->requeteJson('GET', '/api/auth/moi', jeton: $jeton);
        self::assertResponseStatusCodeSame(401);
    }

    public function testProfilInaccessibleSansJetonOuAvecUnJetonInvalide(): void
    {
        $sansJeton = $this->requeteJson('GET', '/api/auth/moi');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Vous devez être connecté.', $sansJeton['message']);

        $jetonInvalide = $this->requeteJson('GET', '/api/auth/moi', jeton: 'ceci.nest.pas.un.jeton');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Session invalide. Veuillez vous reconnecter.', $jetonInvalide['message']);
    }

    public function testConnexionBloqueeApresTropDEchecs(): void
    {
        // Identifiant unique pour ne pas dépendre des tentatives des autres tests.
        $identifiant = 'essai.'.bin2hex(random_bytes(4));
        for ($i = 0; $i < 5; ++$i) {
            $this->connecter($identifiant, 'mauvais');
        }

        $reponse = $this->connecter($identifiant, 'mauvais');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('tentatives', $reponse['message']);
    }

    public function testCorsAutoriseLaVersionWebLocale(): void
    {
        $this->client->request('OPTIONS', '/api/auth/connexion', server: [
            'HTTP_ORIGIN' => 'http://localhost:8081',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        self::assertSame('http://localhost:8081', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Beaucoup d'abonnés mobiles partagent la même IP (NAT de l'opérateur) : des échecs de
     * connexion d'autres personnes ne doivent pas bloquer les utilisateurs déjà connectés.
     */
    public function testEchecsDeConnexionNeBloquentPasLesUtilisateursConnectes(): void
    {
        $jeton = $this->inscrire(['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123'])['token'];
        for ($i = 0; $i < 30; ++$i) {
            $this->connecter('inconnu.'.$i, 'mauvais');
        }

        $this->requeteJson('GET', '/api/auth/moi', jeton: $jeton);

        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $donnees */
    private function inscrire(array $donnees): array
    {
        return $this->requeteJson('POST', '/api/auth/inscription', $donnees);
    }

    private function connecter(string $identifiant, string $motDePasse): array
    {
        return $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => $identifiant, 'motDePasse' => $motDePasse]);
    }
}
