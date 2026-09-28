<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ConversationControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/messages');
        parent::tearDown();
    }

    public function testParcoursCompletLocataireAgent(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $locataire = $this->creerUtilisateur('ama.koffi');
        [$jetonAgent, $jetonLocataire] = [$this->jetonPour($agent), $this->jetonPour($locataire)];

        // Le locataire contacte l'agent depuis l'annonce.
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jetonLocataire);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Kofi', $conversation['interlocuteur']['prenom']);
        self::assertSame('+228 91 11 11 11', $conversation['interlocuteur']['telephone'], 'numéro de l\'annonce');
        self::assertSame($annonce->getTitre(), $conversation['annonce']['titre']);
        $url = '/api/conversations/'.$conversation['id'];

        $envoye = $this->requeteJson('POST', $url.'/messages', ['contenu' => '  Bonjour, la chambre est-elle libre ?  '], $jetonLocataire);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Bonjour, la chambre est-elle libre ?', $envoye['contenu']);
        self::assertSame($locataire->getId(), $envoye['idExpediteur']);
        self::assertFalse($envoye['estLu']);

        // L'agent voit un message non lu…
        self::assertSame(['total' => 1], $this->requeteJson('GET', '/api/conversations/non-lus', jeton: $jetonAgent));
        $listeAgent = $this->requeteJson('GET', '/api/conversations', jeton: $jetonAgent);
        self::assertSame(1, $listeAgent[0]['nonLus']);
        self::assertSame('Ama', $listeAgent[0]['interlocuteur']['prenom']);
        self::assertNull($listeAgent[0]['interlocuteur']['telephone'], 'le numéro du locataire n\'est jamais communiqué');
        self::assertFalse($listeAgent[0]['dernierMessage']['deMoi']);

        // … l'ouvre (il devient lu) et répond.
        $lus = $this->requeteJson('GET', $url.'/messages', jeton: $jetonAgent);
        self::assertCount(1, $lus);
        self::assertSame(['total' => 0], $this->requeteJson('GET', '/api/conversations/non-lus', jeton: $jetonAgent));
        $reponse = $this->requeteJson('POST', $url.'/messages', ['contenu' => 'Oui, elle est disponible.'], $jetonAgent);

        // Le locataire ne récupère que le nouveau message.
        $nouveaux = $this->requeteJson('GET', $url.'/messages?apres='.$envoye['id'], jeton: $jetonLocataire);
        self::assertSame(['Oui, elle est disponible.'], array_column($nouveaux, 'contenu'));
        self::assertSame($reponse['id'], $nouveaux[0]['id']);

        // Et voit que son propre message a été lu par l'agent.
        $tous = $this->requeteJson('GET', $url.'/messages', jeton: $jetonLocataire);
        self::assertTrue($tous[0]['estLu']);
    }

    public function testRecontacterReprendLaMemeConversation(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $premiere = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        $seconde = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($premiere['id'], $seconde['id']);
        self::assertCount(1, $this->requeteJson('GET', '/api/conversations', jeton: $jeton));
    }

    public function testConversationsTrieesParActivite(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $a1 = $this->creerAnnonce($agent, ['titre' => 'Annonce 1']);
        $a2 = $this->creerAnnonce($agent, ['titre' => 'Annonce 2']);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $c1 = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $a1->getId()], $jeton);
        $c2 = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $a2->getId()], $jeton);
        $this->em()->getConnection()->executeStatement('UPDATE conversation SET date_creation = ?, date_dernier_message = NULL', ['2026-01-01 10:00:00']);
        $this->requeteJson('POST', '/api/conversations/'.$c1['id'].'/messages', ['contenu' => 'Relance'], $jeton);

        self::assertSame([$c1['id'], $c2['id']], array_column($this->requeteJson('GET', '/api/conversations', jeton: $jeton), 'id'));
    }

    public function testOnNePeutPasSEcrireSurSaPropreAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $reponse = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('propre annonce', $reponse['violations'][0]['title']);
    }

    public function testAnnonceIntrouvableOuSuspendue(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT), ['statut' => StatutAnnonce::SUSPENDU]);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        self::assertResponseStatusCodeSame(404);
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => 999999], $jeton);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnTiersNAccedePasALaConversation(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        $intrus = $this->jetonPour($this->creerUtilisateur('edem.agbo'));
        $url = '/api/conversations/'.$conversation['id'];

        $this->requeteJson('GET', $url.'/messages', jeton: $intrus);
        self::assertResponseStatusCodeSame(404);
        $this->requeteJson('POST', $url.'/messages', ['contenu' => 'Intrusion'], $intrus);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requeteJson('GET', '/api/conversations', jeton: $intrus));

        $this->requeteJson('GET', '/api/conversations');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMessageVideRefuse(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);

        $reponse = $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => '   '], $jeton);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Le message est vide.', $reponse['violations'][0]['title']);
    }

    public function testEnvoiDUnePhoto(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        $url = '/api/conversations/'.$conversation['id'].'/messages/image';

        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $chemin = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($chemin, ob_get_clean());
        $this->client->request('POST', $url, ['legende' => 'Ma pièce d\'identité'], ['fichier' => new UploadedFile($chemin, 'photo.png', test: true)], ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'HTTP_ACCEPT' => 'application/json']);
        $message = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('image', $message['type']);
        self::assertSame('Ma pièce d\'identité', $message['contenu']);
        self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/public'.$message['urlFichier']);

        // Un fichier qui n'est pas une image est refusé.
        $faux = tempnam(sys_get_temp_dir(), 'faux');
        file_put_contents($faux, 'pas une image');
        $this->client->request('POST', $url, [], ['fichier' => new UploadedFile($faux, 'x.jpg', test: true)], ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        @unlink($faux);
    }

    public function testLaConversationSurvitALaSuppressionDeLAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jeton);
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Bonjour'], $jeton);

        $this->requeteJson('DELETE', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($agent));

        $liste = $this->requeteJson('GET', '/api/conversations', jeton: $jeton);
        self::assertNull($liste[0]['annonce']);
        self::assertSame('Bonjour', $liste[0]['dernierMessage']['contenu']);
    }
}
