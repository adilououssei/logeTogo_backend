<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Tests\OutilsApi;
use App\Tests\ServeurExpoSimule;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Se connecter avec Apple » tel que l'application l'utilise : jeton Apple → compte existant,
 * compte lié par email, ou nouveau compte après l'écran « Compléter votre profil ».
 */
final class ConnexionAppleTest extends WebTestCase
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
        $reponse = $this->apple(ServeurExpoSimule::jetonApple());

        self::assertResponseIsSuccessful();
        self::assertSame(['inscriptionRequise' => true, 'prenom' => null, 'nom' => null, 'email' => 'ama.koffi@gmail.com'], $reponse);
        self::assertSame(0, $this->em()->getRepository(Utilisateur::class)->count([]), 'aucun compte créé sans région');
    }

    public function testNouveauCompteCreeAvecLeNomEnvoyeParLApplicationPuisReconnu(): void
    {
        $jeton = ServeurExpoSimule::jetonApple();
        $inscription = $this->apple($jeton, ['region' => 'plateaux', 'prenom' => 'Ama', 'nom' => 'Koffi']);

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($inscription['token']);
        self::assertSame('ama.koffi', $inscription['utilisateur']['identifiant']);
        self::assertSame('plateaux', $inscription['utilisateur']['region']);

        // Connexion suivante : le compte est retrouvé par Apple, sans rien redemander.
        $connexion = $this->apple($jeton);
        self::assertSame($inscription['utilisateur']['id'], $connexion['utilisateur']['id']);
        self::assertSame(1, $this->em()->getRepository(Utilisateur::class)->count([]));
    }

    public function testCompteExistantAvecLeMemeEmailEstLieAApple(): void
    {
        $existant = $this->creerUtilisateur('ama.koffi');
        $existant->setEmail('ama.koffi@gmail.com');
        $this->em()->flush();

        $reponse = $this->apple(ServeurExpoSimule::jetonApple(['email' => 'Ama.Koffi@gmail.com']));

        self::assertResponseIsSuccessful();
        self::assertSame($existant->getId(), $reponse['utilisateur']['id']);
        $this->em()->clear();
        self::assertSame('000123.abcdef.4567', $this->em()->find(Utilisateur::class, $existant->getId())->getAppleId());
    }

    public function testEmailNonVerifieNeLiePasUnCompteExistant(): void
    {
        $existant = $this->creerUtilisateur('ama.koffi');
        $existant->setEmail('ama.koffi@gmail.com');
        $this->em()->flush();

        $reponse = $this->apple(ServeurExpoSimule::jetonApple(['email_verified' => 'false']));

        self::assertTrue($reponse['inscriptionRequise']);
        self::assertNull($reponse['email']);
    }

    public function testCompteSuspenduRefuse(): void
    {
        $suspendu = $this->creerUtilisateur('ama.koffi');
        $suspendu->setEmail('ama.koffi@gmail.com')->setEstActif(false);
        $this->em()->flush();

        $this->apple(ServeurExpoSimule::jetonApple());

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{0: string}> */
    public static function jetonsInvalides(): iterable
    {
        yield 'pas un jeton' => ['nimportequoi'];
        yield 'signé par une autre clé' => [ServeurExpoSimule::jetonApple(cle: 'autre-cle-privee.pem')];
        yield 'pour une autre application' => [ServeurExpoSimule::jetonApple(['aud' => 'com.pirate.autre'])];
        yield 'autre émetteur' => [ServeurExpoSimule::jetonApple(['iss' => 'https://pirate.example'])];
        yield 'expiré' => [ServeurExpoSimule::jetonApple(['exp' => new \DateTimeImmutable('-10 minutes')])];
    }

    #[DataProvider('jetonsInvalides')]
    public function testJetonInvalideRefuse(string $jeton): void
    {
        $this->apple($jeton, ['region' => 'maritime']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->em()->getRepository(Utilisateur::class)->count([]));
    }

    /**
     * @param array<string, mixed> $complement
     *
     * @return array<string, mixed>
     */
    private function apple(string $jeton, array $complement = []): array
    {
        return $this->requeteJson('POST', '/api/auth/apple', ['jeton' => $jeton] + $complement);
    }
}
