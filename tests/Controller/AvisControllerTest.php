<?php

namespace App\Tests\Controller;

use App\Entity\Annonce;
use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AvisControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testNoterUnLogementApresAvoirEchange(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->echanger($annonce, $jeton);

        $reponse = $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 4, 'commentaire' => '  Chambre propre, agent ponctuel.  '], $jeton);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(4.0, (float) $reponse['moyenne']);
        self::assertSame(1, $reponse['nombre']);
        self::assertSame('Chambre propre, agent ponctuel.', $reponse['monAvis']['commentaire']);
        self::assertSame('Ama K.', $reponse['avis'][0]['auteur'], 'prénom et initiale seulement');
        self::assertTrue($reponse['avis'][0]['deMoi']);

        // L'agent est prévenu.
        $notifications = $this->requeteJson('GET', '/api/notifications', jeton: $this->jetonPour($agent));
        self::assertSame('avis', $notifications[0]['type']);
        self::assertStringContainsString('4/5', $notifications[0]['contenu']);

        // La note apparaît dans la liste publique et le détail.
        $liste = $this->requeteJson('GET', '/api/annonces');
        self::assertSame(4.0, (float) $liste['elements'][0]['noteMoyenne']);
        self::assertSame(1, $liste['elements'][0]['nombreAvis']);
        self::assertSame(1, $this->requeteJson('GET', '/api/annonces/'.$annonce->getId())['nombreAvis']);
    }

    public function testModifierSonAvisNeLeDoublePas(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->echanger($annonce, $jeton);

        $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 2], $jeton);
        $reponse = $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 5, 'commentaire' => 'Finalement très bien'], $jeton);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $reponse['nombre']);
        self::assertSame(5.0, (float) $reponse['moyenne']);
    }

    public function testSansEchangePasDAvis(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $refus = $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 1], $jeton);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Échangez d\'abord', $refus['detail']);

        // Une conversation ouverte sans aucun message ne suffit pas non plus.
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 1], $jeton);
        self::assertResponseStatusCodeSame(403);

        self::assertFalse($this->requeteJson('GET', '/api/annonces/'.$annonce->getId().'/avis', jeton: $jeton)['peutNoter']);
    }

    public function testNoterUnAgent(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $jetonEdem = $this->jetonPour($this->creerUtilisateur('edem.agbo'));
        $this->echanger($annonce, $jetonAma);
        $this->echanger($annonce, $jetonEdem);

        $this->requeteJson('PUT', '/api/agents/'.$agent->getId().'/avis', ['note' => 5], $jetonAma);
        self::assertResponseStatusCodeSame(201);
        $reponse = $this->requeteJson('PUT', '/api/agents/'.$agent->getId().'/avis', ['note' => 4], $jetonEdem);

        self::assertSame(4.5, (float) $reponse['moyenne']);
        self::assertSame(2, $reponse['nombre']);
        // La note de l'agent accompagne ses annonces.
        $publiePar = $this->requeteJson('GET', '/api/annonces')['elements'][0]['publiePar'];
        self::assertSame(4.5, (float) $publiePar['noteMoyenne']);
        self::assertSame(2, $publiePar['nombreAvis']);
    }

    public function testOnNePeutPasSeNoterNiNoterUnLocataire(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $locataire = $this->creerUtilisateur('ama.koffi');

        $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 5], $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(403);

        $this->requeteJson('GET', '/api/agents/'.$locataire->getId().'/avis');
        self::assertResponseStatusCodeSame(404);
    }

    public function testValidationEtSuppression(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->echanger($annonce, $jeton);
        $url = '/api/annonces/'.$annonce->getId().'/avis';

        $this->requeteJson('PUT', $url, ['note' => 6], $jeton);
        self::assertResponseStatusCodeSame(422);
        $this->requeteJson('PUT', $url, ['commentaire' => 'Sans note'], $jeton);
        self::assertResponseStatusCodeSame(422);

        $this->requeteJson('PUT', $url, ['note' => 3], $jeton);
        $this->requeteJson('DELETE', $url, jeton: $jeton);
        self::assertResponseStatusCodeSame(204);

        $public = $this->requeteJson('GET', $url);
        self::assertSame(0, $public['nombre']);
        self::assertNull($public['moyenne']);
        self::assertFalse($public['peutNoter'], 'visiteur');

        $this->requeteJson('PUT', $url, ['note' => 3]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAvisMasquesParLaModerationNonComptes(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->echanger($annonce, $jeton);
        $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId().'/avis', ['note' => 1, 'commentaire' => 'Insultes'], $jeton);

        $this->em()->getConnection()->executeStatement('UPDATE avis SET est_masque = 1');

        $public = $this->requeteJson('GET', '/api/annonces/'.$annonce->getId().'/avis');
        self::assertSame(0, $public['nombre']);
        self::assertSame([], $public['avis']);
    }

    /** Le locataire contacte l'agent et envoie un message (condition pour pouvoir noter). */
    private function echanger(Annonce $annonce, string $jeton): void
    {
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Bonjour'], $jeton);
    }
}
