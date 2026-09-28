<?php

namespace App\Tests\Controller;

use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProfilControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/avatars');
        parent::tearDown();
    }

    public function testModifierSonProfilSansChangerDIdentifiant(): void
    {
        $session = $this->inscrire();

        $profil = $this->requeteJson('PUT', '/api/profil', [
            'prenom' => 'Kofi', 'nom' => 'Mensah-Adjo', 'region' => 'kara',
            'email' => 'Kofi@Exemple.TG', 'telephone' => '90 11 22 33', 'indicatifPays' => '+228',
            'whatsapp' => '99 11 22 33',
        ], $session['token']);

        self::assertResponseIsSuccessful();
        self::assertSame('Mensah-Adjo', $profil['nom']);
        self::assertSame('kara', $profil['region']);
        self::assertSame('kofi@exemple.tg', $profil['email']);
        self::assertSame('90 11 22 33', $profil['telephone']);
        self::assertSame('99 11 22 33', $profil['whatsapp']);
        self::assertSame('kofi.mensah', $profil['identifiant'], 'l\'identifiant de connexion ne change pas');

        // Retirer l'email et le téléphone.
        $profil = $this->requeteJson('PUT', '/api/profil', ['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'kara', 'email' => '', 'telephone' => ''], $session['token']);
        self::assertNull($profil['email']);
        self::assertNull($profil['telephone']);
        self::assertNull($profil['whatsapp']);
        self::assertNull($profil['indicatifPays']);
    }

    public function testEmailDejaUtiliseRefuseSansRienModifier(): void
    {
        $this->requeteJson('POST', '/api/auth/inscription', ['prenom' => 'Ama', 'nom' => 'Koffi', 'region' => 'maritime', 'motDePasse' => 'secret123', 'email' => 'ama@exemple.tg']);
        $session = $this->inscrire();

        $reponse = $this->requeteJson('PUT', '/api/profil', ['prenom' => 'Autre', 'nom' => 'Nom', 'region' => 'maritime', 'email' => 'ama@exemple.tg'], $session['token']);

        self::assertResponseStatusCodeSame(422);
        self::assertContains('Cet email est déjà utilisé.', array_column($reponse['violations'], 'title'));
        self::assertSame('Kofi', $this->requeteJson('GET', '/api/auth/moi', jeton: $session['token'])['prenom']);
    }

    public function testChangerDeMotDePasseDeconnecteLesAutresAppareils(): void
    {
        $session = $this->inscrire();
        $autreTelephone = $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'secret123']);

        $refus = $this->requeteJson('PUT', '/api/profil/mot-de-passe', ['motDePasseActuel' => 'faux', 'nouveauMotDePasse' => 'nouveau456'], $session['token']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Mot de passe actuel incorrect.', $refus['violations'][0]['title']);

        $nouveau = $this->requeteJson('PUT', '/api/profil/mot-de-passe', ['motDePasseActuel' => 'secret123', 'nouveauMotDePasse' => 'nouveau456'], $session['token']);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($nouveau['refresh_token'], 'cet appareil reste connecté');

        // L'autre téléphone ne peut plus renouveler sa session.
        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $autreTelephone['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $nouveau['refresh_token']]);
        self::assertResponseIsSuccessful();

        // Le nouveau mot de passe fonctionne, pas l'ancien.
        $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'secret123']);
        self::assertResponseStatusCodeSame(401);
        $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'nouveau456']);
        self::assertResponseIsSuccessful();
    }

    public function testPhotoDeProfil(): void
    {
        $session = $this->inscrire();
        $racine = static::getContainer()->getParameter('kernel.project_dir').'/public';

        $profil = $this->envoyerPhoto($session['token']);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#^/uploads/avatars/\d+/[0-9a-f]{24}\.png$#', $profil['avatar']);
        self::assertFileExists($racine.$profil['avatar']);

        // Une nouvelle photo remplace l'ancienne (fichier supprimé).
        $nouveau = $this->envoyerPhoto($session['token']);
        self::assertFileDoesNotExist($racine.$profil['avatar']);

        $sansPhoto = $this->requeteJson('DELETE', '/api/profil/avatar', jeton: $session['token']);
        self::assertNull($sansPhoto['avatar']);
        self::assertFileDoesNotExist($racine.$nouveau['avatar']);
    }

    public function testVisiteurRefuse(): void
    {
        $this->requeteJson('PUT', '/api/profil', ['prenom' => 'X', 'nom' => 'Y', 'region' => 'kara']);
        self::assertResponseStatusCodeSame(401);
    }

    /** @return array{token: string, refresh_token: string} */
    private function inscrire(): array
    {
        return $this->requeteJson('POST', '/api/auth/inscription', ['prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123']);
    }

    private function envoyerPhoto(string $jeton): array
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $chemin = tempnam(sys_get_temp_dir(), 'avatar');
        file_put_contents($chemin, ob_get_clean());
        $this->client->request('POST', '/api/profil/avatar', files: ['fichier' => new UploadedFile($chemin, 'moi.png', test: true)], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
