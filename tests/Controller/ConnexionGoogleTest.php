<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Tests\OutilsApi;
use App\Tests\ServeurExpoSimule;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Continuer avec Google » tel que l'application l'utilise : jeton Google → compte existant,
 * compte lié par email, ou nouveau compte après l'écran « Compléter votre profil ».
 */
final class ConnexionGoogleTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
        static::getContainer()->get('cache.app')->clear();
    }

    public function testNouveauCompteDemandeDabordDeCompleterLeProfil(): void
    {
        $reponse = $this->google(ServeurExpoSimule::jetonGoogle());

        self::assertResponseIsSuccessful();
        self::assertSame(['inscriptionRequise' => true, 'prenom' => 'Ama', 'nom' => 'Koffi', 'email' => 'ama.koffi@gmail.com'], $reponse);
        self::assertSame(0, $this->em()->getRepository(Utilisateur::class)->count([]), 'aucun compte créé sans région');
    }

    public function testNouveauCompteCreeAvecLaRegionPuisReconnuALaConnexionSuivante(): void
    {
        $jeton = ServeurExpoSimule::jetonGoogle();
        $inscription = $this->google($jeton, ['region' => 'plateaux', 'telephone' => '90 00 00 02', 'indicatifPays' => '+228']);

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($inscription['token']);
        self::assertNotEmpty($inscription['refresh_token']);
        self::assertSame('ama.koffi', $inscription['utilisateur']['identifiant']);
        self::assertSame('plateaux', $inscription['utilisateur']['region']);
        self::assertSame('ama.koffi@gmail.com', $inscription['utilisateur']['email']);

        // Connexion suivante : le compte est retrouvé par Google, sans rien redemander.
        $connexion = $this->google($jeton);
        self::assertSame($inscription['utilisateur']['id'], $connexion['utilisateur']['id']);
        self::assertSame(1, $this->em()->getRepository(Utilisateur::class)->count([]));
    }

    public function testPrenomEtNomCorrigesSurLecranCompleter(): void
    {
        $reponse = $this->google(ServeurExpoSimule::jetonGoogle(), ['region' => 'maritime', 'prenom' => 'Amavi', 'nom' => 'Koffi-Mensah']);

        self::assertSame('Amavi', $reponse['utilisateur']['prenom']);
        self::assertSame('Koffi-Mensah', $reponse['utilisateur']['nom']);
    }

    public function testCompteExistantAvecLeMemeEmailEstLieAGoogle(): void
    {
        $existant = $this->creerUtilisateur('ama.koffi');
        $existant->setEmail('ama.koffi@gmail.com');
        $this->em()->flush();

        $reponse = $this->google(ServeurExpoSimule::jetonGoogle(['email' => 'Ama.Koffi@gmail.com']));

        self::assertResponseIsSuccessful();
        self::assertSame($existant->getId(), $reponse['utilisateur']['id']);
        $this->em()->clear();
        self::assertSame('1234567890', $this->em()->find(Utilisateur::class, $existant->getId())->getGoogleId());
    }

    public function testEmailNonVerifieParGoogleNeLiePasUnCompteExistant(): void
    {
        $existant = $this->creerUtilisateur('ama.koffi');
        $existant->setEmail('ama.koffi@gmail.com');
        $this->em()->flush();

        $reponse = $this->google(ServeurExpoSimule::jetonGoogle(['email_verified' => false]));

        self::assertTrue($reponse['inscriptionRequise']);
        self::assertNull($reponse['email']);
    }

    public function testCompteSuspenduRefuse(): void
    {
        $suspendu = $this->creerUtilisateur('ama.koffi');
        $suspendu->setEmail('ama.koffi@gmail.com')->setEstActif(false);
        $this->em()->flush();

        $this->google(ServeurExpoSimule::jetonGoogle());

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{0: string}> */
    public static function jetonsInvalides(): iterable
    {
        yield 'pas un jeton' => ['nimportequoi'];
        yield 'signé par une autre clé' => [ServeurExpoSimule::jetonGoogle(cle: 'autre-cle-privee.pem')];
        yield 'pour une autre application' => [ServeurExpoSimule::jetonGoogle(['aud' => 'autre-appli.apps.googleusercontent.com'])];
        yield 'autre émetteur' => [ServeurExpoSimule::jetonGoogle(['iss' => 'https://pirate.example'])];
        yield 'expiré' => [ServeurExpoSimule::jetonGoogle(['exp' => new \DateTimeImmutable('-10 minutes')])];
    }

    #[DataProvider('jetonsInvalides')]
    public function testJetonInvalideRefuse(string $jeton): void
    {
        $this->google($jeton, ['region' => 'maritime']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->em()->getRepository(Utilisateur::class)->count([]));
    }

    /**
     * @param array<string, mixed> $complement
     *
     * @return array<string, mixed>
     */
    private function google(string $jeton, array $complement = []): array
    {
        return $this->requeteJson('POST', '/api/auth/google', ['jeton' => $jeton] + $complement);
    }
}
