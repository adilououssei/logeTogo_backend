<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class VerificationControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/verifications');
        parent::tearDown();
    }

    public function testDemandePuisApprobationParLAdministrateur(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $jeton = $this->jetonPour($agent);

        $etat = $this->requeteJson('GET', '/api/verification', jeton: $jeton);
        self::assertFalse($etat['verifie']);
        self::assertNull($etat['demande']);
        self::assertArrayHasKey('cni', $etat['typesDocument']);

        $etat = $this->envoyer($jeton, 'cni', verso: true);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('en_attente', $etat['demande']['statut']);
        self::assertArrayNotHasKey('recto', $etat['demande'], 'les chemins des photos ne sont jamais renvoyés');

        $dossier = static::getContainer()->getParameter('kernel.project_dir').'/var/verifications/'.$agent->getId();
        self::assertCount(2, glob($dossier.'/*'));

        // Une seule demande à la fois.
        $refus = $this->envoyer($jeton, 'cni');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Une demande est déjà en cours d\'examen.', $refus['violations'][0]['title']);

        // L'administrateur voit la demande puis l'approuve.
        $console = new CommandTester((new Application(static::$kernel))->find('app:verifications'));
        $console->execute([]);
        self::assertStringContainsString('kofi.mensah', $console->getDisplay());
        $console->execute(['id' => (string) $etat['demande']['id'], '--approuver' => true]);
        $console->assertCommandIsSuccessful();

        self::assertSame([], glob($dossier.'/*'), 'photos supprimées après la décision');
        $etat = $this->requeteJson('GET', '/api/verification', jeton: $jeton);
        self::assertTrue($etat['verifie']);
        self::assertSame('approuvee', $etat['demande']['statut']);
        self::assertTrue($this->requeteJson('GET', '/api/auth/moi', jeton: $jeton)['verifie']);

        $notifications = $this->requeteJson('GET', '/api/notifications', jeton: $jeton);
        self::assertSame('verification', $notifications[0]['type']);
        self::assertSame('Identité vérifiée', $notifications[0]['titre']);

        $this->envoyer($jeton, 'passeport');
        self::assertResponseStatusCodeSame(422, 'déjà vérifié');
    }

    public function testRefusMotiveEtNouvelleDemandePossible(): void
    {
        $jeton = $this->jetonPour($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $demande = $this->envoyer($jeton, 'passeport')['demande'];

        $console = new CommandTester((new Application(static::$kernel))->find('app:verifications'));
        $console->execute(['id' => (string) $demande['id'], '--refuser' => 'Photo floue']);
        $console->assertCommandIsSuccessful();

        $etat = $this->requeteJson('GET', '/api/verification', jeton: $jeton);
        self::assertFalse($etat['verifie']);
        self::assertSame('refusee', $etat['demande']['statut']);
        self::assertSame('Photo floue', $etat['demande']['motifRefus']);

        $this->envoyer($jeton, 'passeport');
        self::assertResponseStatusCodeSame(201);
    }

    public function testDonneesInvalides(): void
    {
        $jeton = $this->jetonPour($this->creerUtilisateur('kofi.mensah'));

        $reponse = $this->envoyer($jeton, 'carte_de_fidelite');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('typeDocument', $reponse['violations'][0]['propertyPath']);

        $this->client->request('POST', '/api/verification', ['typeDocument' => 'cni'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);

        $this->requeteJson('GET', '/api/verification');
        self::assertResponseStatusCodeSame(401);
    }

    private function envoyer(string $jeton, string $typeDocument, bool $verso = false): array
    {
        $fichiers = ['recto' => $this->photo()];
        if ($verso) {
            $fichiers['verso'] = $this->photo();
        }
        $this->client->request('POST', '/api/verification', ['typeDocument' => $typeDocument], $fichiers, ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function photo(): UploadedFile
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $chemin = tempnam(sys_get_temp_dir(), 'piece');
        file_put_contents($chemin, ob_get_clean());

        return new UploadedFile($chemin, 'piece.png', test: true);
    }
}
