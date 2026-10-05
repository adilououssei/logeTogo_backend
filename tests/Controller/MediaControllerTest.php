<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaControllerTest extends WebTestCase
{
    use OutilsApi;

    /** @var list<string> */
    private array $fichiersTemporaires = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([...$this->fichiersTemporaires, static::getContainer()->getParameter('app.dossier_public').'/uploads/annonces']);
        parent::tearDown();
    }

    public function testLAuteurAjouteDesPhotos(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $reponse = $this->envoyer($annonce->getId(), [$this->photo(), $this->photo()], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(201);
        self::assertCount(2, $reponse);
        self::assertSame('image', $reponse[0]['type']);
        self::assertSame([0, 1], array_column($reponse, 'ordre'));
        self::assertMatchesRegularExpression('#^/uploads/annonces/\d+/[0-9a-f]{24}\.png$#', $reponse[0]['url']);
        self::assertFileExists(static::getContainer()->getParameter('app.dossier_public').$reponse[0]['url']);

        // Les photos apparaissent dans la liste publique.
        $liste = $this->requeteJson('GET', '/api/annonces');
        self::assertSame(array_column($reponse, 'url'), array_column($liste['elements'][0]['medias'], 'url'));
    }

    public function testFichierNonAccepteRefuse(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $faux = $this->fichierTemporaire('ceci n\'est pas une image', 'photo.jpg');

        $reponse = $this->envoyer($annonce->getId(), [$faux], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Format non accepté', $reponse['violations'][0]['title']);
    }

    public function testMaximumHuitPhotos(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $reponse = $this->envoyer($annonce->getId(), array_map(fn () => $this->photo(), range(1, 9)), $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
        self::assertContains('8 photos au maximum par annonce.', array_column($reponse['violations'], 'title'));
    }

    public function testAucunFichier(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $this->envoyer($annonce->getId(), [], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
    }

    public function testSeulLAuteurGereLesMedias(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $autre = $this->creerUtilisateur('edem.agbo', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $this->envoyer($annonce->getId(), [$this->photo()], $this->jetonPour($autre));
        self::assertResponseStatusCodeSame(403);

        $this->envoyer($annonce->getId(), [$this->photo()]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSuppressionDUnMediaEtDeSonFichier(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $media = $this->envoyer($annonce->getId(), [$this->photo()], $this->jetonPour($agent))[0];
        $chemin = static::getContainer()->getParameter('app.dossier_public').$media['url'];

        $this->requeteJson('DELETE', \sprintf('/api/annonces/%d/medias/%d', $annonce->getId(), $media['id']), jeton: $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(204);
        self::assertFileDoesNotExist($chemin);
        self::assertSame([], $this->requeteJson('GET', '/api/annonces/'.$annonce->getId())['medias']);
    }

    public function testSupprimerLAnnonceSupprimeSesFichiers(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $media = $this->envoyer($annonce->getId(), [$this->photo()], $this->jetonPour($agent))[0];
        $chemin = static::getContainer()->getParameter('app.dossier_public').$media['url'];

        $this->requeteJson('DELETE', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(204);
        self::assertFileDoesNotExist($chemin);
    }

    /** @param list<UploadedFile> $fichiers */
    private function envoyer(int $idAnnonce, array $fichiers, ?string $jeton = null): array
    {
        $entetes = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $jeton) {
            $entetes['HTTP_AUTHORIZATION'] = 'Bearer '.$jeton;
        }
        $this->client->request('POST', "/api/annonces/$idAnnonce/medias", files: ['fichiers' => $fichiers], server: $entetes);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /** Vraie image PNG de 4×4 pixels. */
    private function photo(): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return $this->fichierTemporaire((string) ob_get_clean(), 'photo.png');
    }

    private function fichierTemporaire(string $contenu, string $nomOriginal): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'media');
        file_put_contents($chemin, $contenu);
        $this->fichiersTemporaires[] = $chemin;

        return new UploadedFile($chemin, $nomOriginal, test: true);
    }
}
