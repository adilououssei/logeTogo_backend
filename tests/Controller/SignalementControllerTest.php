<?php

namespace App\Tests\Controller;

use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SignalementControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    public function testSignalerUneAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $motifs = $this->requeteJson('GET', '/api/signalements/motifs');
        self::assertContains('arnaque', array_column($motifs, 'valeur'));

        $reponse = $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/signalements', ['motif' => 'arnaque', 'commentaire' => ' Demande un acompte avant visite '], $jeton);
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('transmis', $reponse['message']);

        // Pas deux signalements en attente de la même personne pour la même annonce.
        $refus = $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/signalements', ['motif' => 'doublon'], $jeton);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('déjà signalé', $refus['violations'][0]['title']);
    }

    public function testRefus(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/signalements', ['motif' => 'arnaque']);
        self::assertResponseStatusCodeSame(401, 'visiteur');

        $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/signalements', ['motif' => 'arnaque'], $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(422, 'sa propre annonce');

        $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/signalements', ['motif' => 'inconnu'], $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertResponseStatusCodeSame(422, 'motif inconnu');
    }
}
