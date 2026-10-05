<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Service\AnnonceVocale\ExtracteurRegles;
use App\Service\AnnonceVocale\NombresEnLettres;
use App\Service\AnnonceVocale\Transcripteur;
use App\Service\AnnonceVocale\TranscriptionImpossible;
use App\Tests\OutilsApi;
use App\Tests\ServeurExpoSimule;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class BrouillonVocalControllerTest extends WebTestCase
{
    use OutilsApi;

    private const CHAMBRE_SALON = "Bonjour, j'ai une chambre salon à louer à Agoè Assiyéyé, pas loin du marché. C'est vingt-cinq mille par mois, six mois d'avance, trois mois de caution, la commission c'est vingt-cinq mille. Il y a douche interne, compteur personnel, et un forage dans la cour.";

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
        ServeurExpoSimule::vider();
    }

    public function testNombresDitsEnLettres(): void
    {
        foreach ([
            'vingt-cinq mille' => '25000',
            'deux cent cinquante mille' => '250000',
            'un million deux cent mille' => '1200000',
            'quatre-vingt-dix mille' => '90000',
            'soixante-quinze mille' => '75000',
            'trois millions cinq cent mille' => '3500000',
            '25 000 francs' => '25000 francs',
            '1,5 million' => '1500000',
            'une chambre et un salon' => 'une chambre et un salon',
        ] as $dit => $attendu) {
            self::assertSame($attendu, NombresEnLettres::convertir($dit), $dit);
        }
    }

    public function testReglesSurDesAnnoncesTypiques(): void
    {
        $regles = new ExtracteurRegles();

        $chambre = $regles->extraire(self::CHAMBRE_SALON);
        self::assertSame(['chambre', 'location', 25000, 6, 3, 25000], [$chambre['typeBien']->value, $chambre['typeTransaction']->value, $chambre['prix'], $chambre['avanceMois'], $chambre['cautionMois'], $chambre['commission']]);
        self::assertSame(['Agoè Assiyéyé', 'Lomé', 'maritime'], [$chambre['quartier'], $chambre['ville'], $chambre['region']->value]);
        self::assertSame('Pas loin du marché', $chambre['adresse']);
        self::assertSame(['Salle de bain privée', 'Compteur personnel', 'Forage'], $chambre['equipements']);
        self::assertSame('Chambre salon à louer à Agoè Assiyéyé', $chambre['titre']);
        self::assertStringStartsWith("J'ai une chambre salon", $chambre['description'], 'formule de politesse retirée');

        $villa = $regles->extraire('Villa de quatre chambres à vendre à Baguida, trois salles de bain, cinq cents mètres carrés, titre foncier, clôturée avec garage. Le prix est de quatre-vingt-cinq millions.');
        self::assertSame(['villa', 'vente', 85_000_000, 4, 3, 500], [$villa['typeBien']->value, $villa['typeTransaction']->value, $villa['prix'], $villa['chambres'], $villa['sallesDeBain'], $villa['superficie']]);
        self::assertSame('Villa 4 chambres à vendre à Baguida', $villa['titre']);

        $terrain = $regles->extraire('Terrain à vendre à Kpalimé, un lot de 600 m2, borné, prix 8 500 000 francs.');
        self::assertSame(['terrain', 8_500_000, 600, 'Kpalimé', 'plateaux'], [$terrain['typeBien']->value, $terrain['prix'], $terrain['superficie'], $terrain['ville'], $terrain['region']->value]);
        self::assertNull($terrain['quartier']);

        // Expressions togolaises : « mois devant » (avance), « garantie » (caution), compteur « individuel ».
        $appart = $regles->extraire("Appartement de deux chambres à Adidogomé, vers la pharmacie Sainte Rita. Le loyer c'est cent vingt mille, quatre mois devant plus deux mois de garantie. Le compteur est individuel, cour pavée et gardien.");
        self::assertSame(['location', 120000, 4, 2], [$appart['typeTransaction']->value, $appart['prix'], $appart['avanceMois'], $appart['cautionMois']]);
        self::assertSame('Vers la pharmacie Sainte Rita', $appart['adresse']);
        self::assertSame(['Compteur personnel', 'Gardien', 'Cour pavée'], $appart['equipements']);
    }

    public function testIaInutileQuandLesReglesOntToutCompris(): void
    {
        $reponse = $this->requeteJson('POST', '/api/annonces/brouillon-vocal', ['texte' => self::CHAMBRE_SALON], $this->jetonAgent());

        self::assertResponseIsSuccessful();
        self::assertSame(0, ServeurExpoSimule::$appelsIa, 'l\'IA (lente) n\'est pas appelée');
        self::assertSame('regles', $reponse['moteur']);
        self::assertSame(25000, $reponse['annonce']['prix']);
        self::assertSame('maritime', $reponse['annonce']['region']);
        self::assertSame([], $reponse['champsManquants']);
    }

    public function testReglesSeulesQuandLIaEstArretee(): void
    {
        // Pas d'avance : l'IA est essayée, mais elle est arrêtée.
        $reponse = $this->requeteJson('POST', '/api/annonces/brouillon-vocal', ['texte' => 'Chambre salon à louer à Tokoin, vingt-cinq mille par mois.'], $this->jetonAgent());

        self::assertResponseIsSuccessful();
        self::assertSame(1, ServeurExpoSimule::$appelsIa, 'l\'IA a été essayée');
        self::assertSame('regles', $reponse['moteur'], 'IA arrêtée : les règles prennent le relais');
        self::assertSame(25000, $reponse['annonce']['prix']);
        self::assertSame(['avanceMois'], $reponse['champsManquants']);
    }

    public function testLIaCompleteLesRegles(): void
    {
        // Phrase libre que les règles comprennent mal ; l'IA donne le prix et l'avance.
        ServeurExpoSimule::$reponseIa = [
            'typeBien' => 'studio', 'typeTransaction' => 'location', 'prix' => 40000, 'avanceMois' => 4, 'cautionMois' => null,
            'commission' => null, 'chambres' => 1, 'sallesDeBain' => 1, 'superficie' => 99_999_999_999, // hors limites : écartée
        ];

        $reponse = $this->requeteJson('POST', '/api/annonces/brouillon-vocal', ['texte' => 'Un joli studio à Tokoin Hôpital, genre quarante, et puis quatre mois devant.'], $this->jetonAgent());

        self::assertSame('regles+ia', $reponse['moteur']);
        self::assertSame(['studio', 'location', 40000, 4], [$reponse['annonce']['typeBien'], $reponse['annonce']['typeTransaction'], $reponse['annonce']['prix'], $reponse['annonce']['avanceMois']]);
        self::assertNull($reponse['annonce']['superficie']);
        self::assertSame('Tokoin Hôpital', $reponse['annonce']['quartier'], 'le lieu vient des règles');
        self::assertSame([], $reponse['champsManquants']);
    }

    public function testAudioTranscritParWhisper(): void
    {
        ServeurExpoSimule::$transcription = 'Terrain à vendre à Kpalimé, 600 m2, prix huit millions cinq cent mille.';

        $this->client->request('POST', '/api/annonces/brouillon-vocal', files: ['audio' => $this->audio()], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonAgent(), 'HTTP_ACCEPT' => 'application/json']);
        $reponse = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertResponseIsSuccessful();
        self::assertSame(ServeurExpoSimule::$transcription, $reponse['transcription']);
        self::assertSame(8_500_000, $reponse['annonce']['prix']);
        self::assertSame(['quartier'], $reponse['champsManquants'], 'le quartier n\'a pas été dit');
    }

    public function testMessageSelonLaPanneDeWhisper(): void
    {
        foreach ([
            'arrêtée sur le serveur' => new TransportException('Failed to connect to 127.0.0.1 port 8080 after 2041 ms: Could not connect to server'),
            'trop de temps' => new TransportException('Idle timeout reached for "http://127.0.0.1:8080/inference".'),
            'n\'a pas pu être lu' => new MockResponse('{"error":"failed to read audio data"}', ['http_code' => 500]),
        ] as $attendu => $panne) {
            $http = new MockHttpClient(fn () => $panne instanceof MockResponse ? $panne : throw $panne);
            $transcripteur = new Transcripteur($http, new Filesystem(), 'http://127.0.0.1:8080/inference', 5, 'ffmpeg-absent', new NullLogger());
            try {
                $transcripteur->transcrire($this->audio());
                self::fail('Une erreur était attendue : '.$attendu);
            } catch (TranscriptionImpossible $e) {
                self::assertStringContainsString($attendu, $e->getMessage());
            }
        }
    }

    public function testRefus(): void
    {
        $this->requeteJson('POST', '/api/annonces/brouillon-vocal', ['texte' => self::CHAMBRE_SALON], $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertResponseStatusCodeSame(403, 'réservé aux agents et propriétaires');

        $trop = $this->requeteJson('POST', '/api/annonces/brouillon-vocal', ['texte' => 'Chambre'], $this->jetonAgent());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('texte', $trop['violations'][0]['propertyPath']);
    }

    private function jetonAgent(): string
    {
        return $this->jetonPour($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
    }

    private function audio(): UploadedFile
    {
        // En-tête WAV minimal : suffisant pour la détection du type, la transcription est simulée.
        $chemin = tempnam(sys_get_temp_dir(), 'vocal');
        file_put_contents($chemin, 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 0));

        return new UploadedFile($chemin, 'annonce.wav', 'audio/wav', test: true);
    }
}
