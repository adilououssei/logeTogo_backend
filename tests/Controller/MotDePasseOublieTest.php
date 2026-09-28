<?php

namespace App\Tests\Controller;

use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class MotDePasseOublieTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testParcoursCompletJusquALaConnexion(): void
    {
        $session = $this->inscrire('kofi@exemple.tg');

        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => ' Kofi.Mensah ']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('code à 6 chiffres', $reponse['message']);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'kofi@exemple.tg');
        $code = $this->codeDe($email);

        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $code]);
        self::assertResponseIsSuccessful();

        $connexion = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/reinitialiser', [
            'identifiant' => 'kofi.mensah', 'code' => $code, 'nouveauMotDePasse' => 'nouveau456',
        ]);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($connexion['token'], 'l\'utilisateur est connecté directement');
        self::assertSame('kofi.mensah', $connexion['utilisateur']['identifiant']);

        // Les anciennes sessions sont fermées, l'ancien mot de passe ne marche plus.
        $this->requeteJson('POST', '/api/auth/renouveler', ['refresh_token' => $session['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
        $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'secret123']);
        self::assertResponseStatusCodeSame(401);
        $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'nouveau456']);
        self::assertResponseIsSuccessful();

        // Le code ne sert qu'une fois.
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/reinitialiser', [
            'identifiant' => 'kofi.mensah', 'code' => $code, 'nouveauMotDePasse' => 'encore789',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMemeReponseSansCompteNiEmail(): void
    {
        $this->inscrire(null);

        $inconnu = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'personne.inconnue']);
        self::assertResponseIsSuccessful();
        $sansEmail = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        self::assertResponseIsSuccessful();

        self::assertSame($inconnu, $sansEmail, 'rien ne permet de savoir si le compte existe');
        self::assertEmailCount(0);
    }

    public function testCinqEssaisManquesAnnulentLeCode(): void
    {
        $this->inscrire('kofi@exemple.tg');
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        $bon = $this->codeDe(self::getMailerMessage());
        $faux = '000000' === $bon ? '111111' : '000000';

        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $faux]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Code incorrect. Encore 4 essais.', $reponse['violations'][0]['title']);
        self::assertSame('code', $reponse['violations'][0]['propertyPath']);

        for ($i = 0; $i < 3; ++$i) {
            $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $faux]);
        }
        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $faux]);
        self::assertSame('Trop d\'essais. Demandez un nouveau code.', $reponse['violations'][0]['title']);

        // Même le bon code ne passe plus.
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $bon]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNouvelleDemandeRemplaceLAncienCode(): void
    {
        $this->inscrire('kofi@exemple.tg');
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        $ancien = $this->codeDe(self::getMailerMessage());
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        $nouveau = $this->codeDe(self::getMailerMessage());

        if ($ancien !== $nouveau) {
            $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $ancien]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $nouveau]);
        self::assertResponseIsSuccessful();
    }

    public function testCodeExpire(): void
    {
        $this->inscrire('kofi@exemple.tg');
        $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        $code = $this->codeDe(self::getMailerMessage());
        $this->em()->getConnection()->executeStatement('UPDATE code_reinitialisation SET date_expiration = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => $code]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Code expiré ou inexistant. Demandez un nouveau code.', $reponse['violations'][0]['title']);
    }

    public function testDemandesLimitees(): void
    {
        $this->inscrire('kofi@exemple.tg');
        for ($i = 0; $i < 3; ++$i) {
            $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
            self::assertResponseIsSuccessful();
            self::assertEmailCount(1);
        }
        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie', ['identifiant' => 'kofi.mensah']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('Trop de demandes. Réessayez dans quelques minutes.', $reponse['detail']);
        self::assertEmailCount(0);
    }

    public function testCodeMalFormeRefuse(): void
    {
        $reponse = $this->requeteJson('POST', '/api/auth/mot-de-passe-oublie/verifier', ['identifiant' => 'kofi.mensah', 'code' => '12ab']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Le code contient 6 chiffres.', $reponse['violations'][0]['title']);
    }

    /** @return array{token: string, refresh_token: string} */
    private function inscrire(?string $email): array
    {
        return $this->requeteJson('POST', '/api/auth/inscription', array_filter([
            'prenom' => 'Kofi', 'nom' => 'Mensah', 'region' => 'maritime', 'motDePasse' => 'secret123', 'email' => $email,
        ]));
    }

    private function codeDe(?\Symfony\Component\Mime\RawMessage $email): string
    {
        self::assertInstanceOf(Email::class, $email);
        self::assertMatchesRegularExpression('/^(\d{6}) est votre code LogeTogo$/', (string) $email->getSubject());

        return substr((string) $email->getSubject(), 0, 6);
    }
}
