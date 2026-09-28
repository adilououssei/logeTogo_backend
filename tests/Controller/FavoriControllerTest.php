<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FavoriControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testAjouterPuisListerLesFavoris(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $premiere = $this->creerAnnonce($agent, ['titre' => 'Première']);
        $seconde = $this->creerAnnonce($agent, ['titre' => 'Seconde']);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $this->requeteJson('PUT', '/api/favoris/'.$premiere->getId(), jeton: $jeton);
        self::assertResponseStatusCodeSame(204);
        $this->requeteJson('PUT', '/api/favoris/'.$seconde->getId(), jeton: $jeton);

        $favoris = $this->requeteJson('GET', '/api/favoris', jeton: $jeton);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(['Première', 'Seconde'], array_column($favoris, 'titre'));
        // Même format que la liste publique : pas de GPS ni de contacts.
        self::assertArrayNotHasKey('contact', $favoris[0]);
        self::assertArrayNotHasKey('telephone', $favoris[0]['publiePar']);
    }

    public function testAjouterDeuxFoisNeCreeQuUnFavori(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->requeteJson('GET', '/api/favoris', jeton: $jeton));
    }

    public function testRetirerUnFavori(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);

        $this->requeteJson('DELETE', '/api/favoris/'.$annonce->getId(), jeton: $jeton);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requeteJson('GET', '/api/favoris', jeton: $jeton));

        // Retirer une annonce qui n'est pas (ou plus) en favori ne pose pas de problème.
        $this->requeteJson('DELETE', '/api/favoris/'.$annonce->getId(), jeton: $jeton);
        self::assertResponseStatusCodeSame(204);
    }

    public function testChacunSesFavoris(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $ama = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $edem = $this->jetonPour($this->creerUtilisateur('edem.agbo'));

        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $ama);

        self::assertSame([], $this->requeteJson('GET', '/api/favoris', jeton: $edem));
    }

    public function testAnnonceSuspendueOuIntrouvable(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);

        // Suspendue par la modération : elle disparaît des favoris et ne peut plus y être ajoutée.
        $this->em()->getConnection()->executeStatement('UPDATE annonce SET statut = ? WHERE id = ?', [StatutAnnonce::SUSPENDU->value, $annonce->getId()]);
        self::assertSame([], $this->requeteJson('GET', '/api/favoris', jeton: $jeton));
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);
        self::assertResponseStatusCodeSame(404);

        $this->requeteJson('PUT', '/api/favoris/999999', jeton: $jeton);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuppressionDeLAnnonceRetireLeFavori(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jeton);

        $this->requeteJson('DELETE', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($agent));

        self::assertSame([], $this->requeteJson('GET', '/api/favoris', jeton: $jeton));
    }

    public function testVisiteurRefuse(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));

        $this->requeteJson('GET', '/api/favoris');
        self::assertResponseStatusCodeSame(401);
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId());
        self::assertResponseStatusCodeSame(401);
    }
}
