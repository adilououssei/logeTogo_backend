<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StatistiqueControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testVuesFavorisEtContactsParAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $chambre = $this->creerAnnonce($agent);
        $terrain = $this->creerAnnonce($agent, ['titre' => 'Terrain à Kpalimé', 'statut' => StatutAnnonce::VENDU]);
        $this->creerAnnonce($this->creerUtilisateur('ama.agent', RoleUtilisateur::AGENT), ['titre' => 'Annonce d\'un autre agent']);

        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $jetonEdem = $this->jetonPour($this->creerUtilisateur('edem.agbo'));
        foreach ([$jetonAma, $jetonEdem] as $jeton) {
            $this->requeteJson('GET', '/api/annonces/'.$chambre->getId(), jeton: $jeton);
            $this->requeteJson('PUT', '/api/favoris/'.$chambre->getId(), jeton: $jeton);
        }
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $chambre->getId()], $jetonAma);
        $this->requeteJson('GET', '/api/annonces/'.$terrain->getId(), jeton: $jetonAma);

        $stats = $this->requeteJson('GET', '/api/statistiques', jeton: $this->jetonPour($agent));

        self::assertResponseIsSuccessful();
        self::assertSame(['annonces' => 2, 'disponibles' => 1, 'vues' => 3, 'favoris' => 2, 'contacts' => 1, 'noteMoyenne' => null, 'nombreAvis' => 0], $stats['totaux']);
        self::assertCount(2, $stats['annonces'], 'seulement ses propres annonces');
        $premiere = $stats['annonces'][0];
        self::assertSame('Chambre meublée à Adidogomé', $premiere['titre'], 'les plus vues d\'abord');
        self::assertSame(['vues' => 2, 'favoris' => 2, 'contacts' => 1], ['vues' => $premiere['vues'], 'favoris' => $premiere['favoris'], 'contacts' => $premiere['contacts']]);
        self::assertSame('vendu', $stats['annonces'][1]['statut']);
    }

    public function testReserveAuxAnnonceurs(): void
    {
        $this->requeteJson('GET', '/api/statistiques', jeton: $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertResponseStatusCodeSame(403);

        $this->requeteJson('GET', '/api/statistiques');
        self::assertResponseStatusCodeSame(401);
    }
}
