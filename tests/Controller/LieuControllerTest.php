<?php

namespace App\Tests\Controller;

use App\Entity\QuartierAjoute;
use App\Enum\RoleUtilisateur;
use App\Service\AnnonceVocale\LieuxTogo;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LieuControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testSuggestionsPendantLaSaisie(): void
    {
        $noms = array_column($this->requeteJson('GET', '/api/lieux/quartiers?q=ago&ville=Lom%C3%A9'), 'nom');
        self::assertSame('Agoè', $noms[0], 'les noms qui commencent par « ago » d\'abord');
        self::assertContains('Agoè Assiyéyé', $noms);
        self::assertLessThanOrEqual(8, \count($noms));

        // Une autre façon d'écrire (« Kpota ») mène au nom officiel, avec sa ville et sa région.
        $kpota = $this->requeteJson('GET', '/api/lieux/quartiers?q=kpota');
        self::assertSame(['nom' => 'Bè Kpota', 'ville' => 'Lomé', 'region' => 'maritime'], $kpota[0]);

        self::assertSame([], $this->requeteJson('GET', '/api/lieux/quartiers?q='));
    }

    public function testQuartierInconnuAjouteAPublicationPuisPropose(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);

        $annonce = $this->requeteJson('POST', '/api/annonces', $this->donnees('Agoè Kpogli'), $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Agoè Kpogli', $annonce['localisation']['quartier']);

        $ajoute = $this->em()->getRepository(QuartierAjoute::class)->findOneBy(['nomNormalise' => 'agoe kpogli']);
        self::assertNotNull($ajoute);
        self::assertSame(['Lomé', 'kofi.mensah'], [$ajoute->getVille(), $ajoute->getAjoutePar()?->getIdentifiant()]);

        // Proposé ensuite à tous, et reconnu sans être ajouté deux fois.
        self::assertContains('Agoè Kpogli', array_column($this->requeteJson('GET', '/api/lieux/quartiers?q=kpog'), 'nom'));
        $this->requeteJson('POST', '/api/annonces', $this->donnees('agoe kpogli'), $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->em()->getRepository(QuartierAjoute::class)->findAll());
    }

    public function testQuartierConnuEcritCommeDansLaListe(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);

        $annonce = $this->requeteJson('POST', '/api/annonces', $this->donnees('agoe assiyeye'), $this->jetonPour($agent));

        self::assertSame('Agoè Assiyéyé', $annonce['localisation']['quartier']);
        self::assertSame([], $this->em()->getRepository(QuartierAjoute::class)->findAll(), 'déjà dans la liste : rien à ajouter');
    }

    public function testNomsMalTranscritsCorrigesAuSon(): void
    {
        foreach ([
            'Chambre salon à Gwai, à Seye, non loin du marché.' => 'Agoè Assiyéyé',
            'Appartement à Hago et à Syeye, pas loin.' => 'Agoè Assiyéyé',
            'Chambre à Tokoin Opital, à Lomé.' => 'Tokoin Hôpital',
            'Boutique à Akodessewa, bien placée.' => 'Akodésséwa',
            'Chambre à louer à Bé Kpota.' => 'Bè Kpota',
        ] as $transcription => $quartier) {
            self::assertSame($quartier, LieuxTogo::trouver(LieuxTogo::corrigerNoms($transcription))['quartier'], $transcription);
        }

        // Les mots ordinaires ne sont pas pris pour des quartiers.
        $texte = 'Bonjour, la maison est près du château d\'eau et du grand marché.';
        self::assertSame($texte, LieuxTogo::corrigerNoms($texte));
        self::assertNull(LieuxTogo::trouver($texte)['quartier']);
    }

    /** @return array<string, mixed> */
    private function donnees(string $quartier): array
    {
        return [
            'titre' => 'Chambre salon', 'description' => 'Chambre salon avec douche interne.',
            'typeBien' => 'chambre', 'typeTransaction' => 'location', 'prix' => 30000,
            'avanceMois' => 6, 'cautionMois' => 3, 'chambres' => 1, 'sallesDeBain' => 1,
            'region' => 'maritime', 'ville' => 'Lomé', 'quartier' => $quartier,
        ];
    }
}
