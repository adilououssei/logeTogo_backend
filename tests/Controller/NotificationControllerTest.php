<?php

namespace App\Tests\Controller;

use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use App\Tests\ServeurExpoSimule;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationControllerTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
        $this->em()->getConnection()->executeStatement('DELETE FROM appareil');
        $this->em()->getConnection()->executeStatement('DELETE FROM alerte_recherche');
        ServeurExpoSimule::vider();
    }

    public function testNouveauMessageNotifieEtPousseAuDestinataire(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        [$jetonAgent, $jetonLocataire] = [$this->jetonPour($agent), $this->jetonPour($this->creerUtilisateur('ama.koffi'))];
        $this->enregistrerTelephone($jetonAgent, 'ExponentPushToken[agent]');

        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jetonLocataire);
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Bonjour !'], $jetonLocataire);
        self::assertEmailCount(1, message: 'premier message non lu : un email');
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Bonjour !');
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Toujours libre ?'], $jetonLocataire);
        self::assertEmailCount(0, message: 'pas un email par message tant que la conversation n\'est pas lue');

        // Deux messages → une seule notification, mise à jour avec le dernier.
        $notifications = $this->requeteJson('GET', '/api/notifications', jeton: $jetonAgent);
        self::assertCount(1, $notifications);
        self::assertSame('message', $notifications[0]['type']);
        self::assertSame('Nouveau message de Ama Koffi', $notifications[0]['titre']);
        self::assertSame('Toujours libre ?', $notifications[0]['contenu']);
        self::assertSame($conversation['id'], $notifications[0]['idConversation']);
        self::assertSame(['total' => 1], $this->requeteJson('GET', '/api/notifications/non-lues', jeton: $jetonAgent));

        // Un push par message, vers le téléphone de l'agent, avec la conversation à ouvrir.
        self::assertCount(2, ServeurExpoSimule::$envois);
        self::assertSame('ExponentPushToken[agent]', ServeurExpoSimule::$envois[1]['to']);
        self::assertSame(['type' => 'message', 'idConversation' => $conversation['id']], ServeurExpoSimule::$envois[1]['data']);
        // Comme WhatsApp : le nom de l'expéditeur en titre, en bandeau prioritaire (canal « messages »).
        self::assertSame(['Ama Koffi', 'Toujours libre ?', 'messages', 'high'], [
            ServeurExpoSimule::$envois[1]['title'], ServeurExpoSimule::$envois[1]['body'],
            ServeurExpoSimule::$envois[1]['channelId'], ServeurExpoSimule::$envois[1]['priority'],
        ]);

        // L'expéditeur n'est pas notifié de ses propres messages.
        self::assertSame([], $this->requeteJson('GET', '/api/notifications', jeton: $jetonLocataire));

        // Ouvrir la conversation marque la notification comme lue.
        $this->requeteJson('GET', '/api/conversations/'.$conversation['id'].'/messages', jeton: $jetonAgent);
        self::assertSame(['total' => 0], $this->requeteJson('GET', '/api/notifications/non-lues', jeton: $jetonAgent));
    }

    public function testNouvelleAnnonceEnvoyeeATousPourVousSiUneAlerteCorrespond(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $jetonEdem = $this->jetonPour($this->creerUtilisateur('edem.agbo'));
        $this->enregistrerTelephone($jetonAma, 'ExponentPushToken[ama]');

        // Ama cherche une chambre à Lomé à 30 000 FCFA max ; Edem un terrain à Kara.
        $alerte = $this->requeteJson('POST', '/api/alertes', ['region' => 'maritime', 'typeBien' => 'chambre', 'prixMax' => 30000], $jetonAma);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('maritime', $alerte['region']);
        $this->requeteJson('POST', '/api/alertes', ['region' => 'kara', 'typeBien' => 'terrain'], $jetonEdem);

        $this->requeteJson('POST', '/api/annonces', $this->chambre(25000), $this->jetonPour($agent));

        $notificationsAma = $this->requeteJson('GET', '/api/notifications', jeton: $jetonAma);
        self::assertCount(1, $notificationsAma);
        self::assertSame('nouvelle_annonce', $notificationsAma[0]['type']);
        self::assertSame('Nouvelle annonce pour vous', $notificationsAma[0]['titre'], 'son alerte correspond');
        self::assertStringContainsString('Chambre à Bè', $notificationsAma[0]['contenu']);
        self::assertNotNull($notificationsAma[0]['idAnnonce']);
        self::assertSame('nouvelle_annonce', ServeurExpoSimule::$envois[0]['data']['type']);
        self::assertSame('annonces', ServeurExpoSimule::$envois[0]['channelId']);

        // Edem n'a pas d'alerte qui correspond : il est quand même prévenu.
        self::assertSame(['Nouvelle annonce sur LogeTogo'], array_column($this->requeteJson('GET', '/api/notifications', jeton: $jetonEdem), 'titre'));
        // L'auteur ne reçoit rien pour sa propre annonce.
        self::assertSame([], $this->requeteJson('GET', '/api/notifications', jeton: $this->jetonPour($agent)));

        // Trop chère pour l'alerte d'Ama : elle est prévenue, mais sans « pour vous ».
        $this->requeteJson('POST', '/api/annonces', $this->chambre(80000), $this->jetonPour($agent));
        self::assertSame(['Nouvelle annonce sur LogeTogo', 'Nouvelle annonce pour vous'], array_column($this->requeteJson('GET', '/api/notifications', jeton: $jetonAma), 'titre'));
    }

    public function testAlerteAussiParEmailSaufSiDesactive(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('POST', '/api/alertes', ['typeBien' => 'chambre'], $jetonAma);

        $this->requeteJson('POST', '/api/annonces', $this->chambre(25000), $this->jetonPour($agent));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', 'ama.koffi@exemple.tg');
        self::assertEmailTextBodyContains($email, 'Chambre à Bè');

        $profil = $this->requeteJson('PATCH', '/api/profil/preferences', ['alertesEmail' => false], $jetonAma);
        self::assertResponseIsSuccessful();
        self::assertFalse($profil['alertesEmail']);

        $this->requeteJson('POST', '/api/annonces', $this->chambre(26000), $this->jetonPour($agent));
        self::assertEmailCount(0);
        self::assertCount(2, $this->requeteJson('GET', '/api/notifications', jeton: $jetonAma), 'la notification dans l\'application reste envoyée');
    }

    public function testAlerteToutesRegions(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT, Region::KARA);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('POST', '/api/alertes', ['typeBien' => 'chambre'], $jeton);

        $this->requeteJson('POST', '/api/annonces', ['region' => 'kara', 'ville' => 'Kara', 'quartier' => 'Tomdè'] + $this->chambre(20000), $this->jetonPour($agent));

        self::assertCount(1, $this->requeteJson('GET', '/api/notifications', jeton: $jeton));
    }

    public function testChangementDeStatutNotifieCeuxQuiOntLAnnonceEnFavori(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jetonAma);

        $this->requeteJson('PATCH', '/api/annonces/'.$annonce->getId().'/statut', ['statut' => 'occupe'], $this->jetonPour($agent));

        $notifications = $this->requeteJson('GET', '/api/notifications', jeton: $jetonAma);
        self::assertCount(1, $notifications);
        self::assertSame('mise_a_jour_statut', $notifications[0]['type']);
        self::assertStringContainsString('n\'est plus disponible', $notifications[0]['contenu']);

        // Remettre le même statut ne renvoie rien.
        $this->requeteJson('PATCH', '/api/annonces/'.$annonce->getId().'/statut', ['statut' => 'occupe'], $this->jetonPour($agent));
        self::assertCount(1, $this->requeteJson('GET', '/api/notifications', jeton: $jetonAma));
    }

    public function testToutLireEtMarquerUneNotificationLue(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $a1 = $this->creerAnnonce($agent, ['titre' => 'A']);
        $a2 = $this->creerAnnonce($agent, ['titre' => 'B']);
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $this->requeteJson('PUT', '/api/favoris/'.$a1->getId(), jeton: $jeton);
        $this->requeteJson('PUT', '/api/favoris/'.$a2->getId(), jeton: $jeton);
        foreach ([$a1, $a2] as $a) {
            $this->requeteJson('PATCH', '/api/annonces/'.$a->getId().'/statut', ['statut' => 'occupe'], $this->jetonPour($agent));
        }

        $premiere = $this->requeteJson('GET', '/api/notifications', jeton: $jeton)[0];
        $this->requeteJson('POST', '/api/notifications/'.$premiere['id'].'/lue', jeton: $jeton);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(['total' => 1], $this->requeteJson('GET', '/api/notifications/non-lues', jeton: $jeton));

        // La notification d'un autre utilisateur est introuvable.
        $this->requeteJson('POST', '/api/notifications/'.$premiere['id'].'/lue', jeton: $this->jetonPour($this->creerUtilisateur('edem.agbo')));
        self::assertResponseStatusCodeSame(404);

        $this->requeteJson('POST', '/api/notifications/tout-lire', jeton: $jeton);
        self::assertSame(['total' => 0], $this->requeteJson('GET', '/api/notifications/non-lues', jeton: $jeton));
    }

    public function testTelephoneInvalideOublieEtChangementDeCompte(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $jetonAgent = $this->jetonPour($agent);
        $jetonAma = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        // Jeton refusé par Expo (application désinstallée) : il est supprimé après l'envoi.
        $this->enregistrerTelephone($jetonAgent, 'ExponentPushToken[INVALIDE]');
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jetonAma);
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Bonjour'], $jetonAma);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM appareil'));

        // Même téléphone, autre compte : il passe au dernier connecté.
        $this->enregistrerTelephone($jetonAgent, 'ExponentPushToken[partage]');
        $this->enregistrerTelephone($jetonAma, 'ExponentPushToken[partage]');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM appareil'));

        // Déconnexion : le téléphone est retiré.
        $this->requeteJson('DELETE', '/api/notifications/appareils', ['jetonPush' => 'ExponentPushToken[partage]'], $jetonAma);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM appareil'));
    }

    public function testValidations(): void
    {
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));

        $this->requeteJson('POST', '/api/notifications/appareils', ['jetonPush' => 'nimporte-quoi'], $jeton);
        self::assertResponseStatusCodeSame(422);

        $sansCritere = $this->requeteJson('POST', '/api/alertes', [], $jeton);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins un critère', $sansCritere['violations'][0]['title']);

        for ($i = 1; $i <= 10; ++$i) {
            $this->requeteJson('POST', '/api/alertes', ['prixMax' => $i * 10000], $jeton);
        }
        $this->requeteJson('POST', '/api/alertes', ['prixMax' => 999999], $jeton);
        self::assertResponseStatusCodeSame(422);

        $this->requeteJson('GET', '/api/notifications');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSupprimerUneAlerte(): void
    {
        $jeton = $this->jetonPour($this->creerUtilisateur('ama.koffi'));
        $alerte = $this->requeteJson('POST', '/api/alertes', ['typeBien' => 'studio'], $jeton);

        $this->requeteJson('DELETE', '/api/alertes/'.$alerte['id'], jeton: $this->jetonPour($this->creerUtilisateur('edem.agbo')));
        self::assertResponseStatusCodeSame(404);

        $this->requeteJson('DELETE', '/api/alertes/'.$alerte['id'], jeton: $jeton);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->requeteJson('GET', '/api/alertes', jeton: $jeton));
    }

    private function enregistrerTelephone(string $jeton, string $jetonPush): void
    {
        $this->requeteJson('POST', '/api/notifications/appareils', ['jetonPush' => $jetonPush, 'plateforme' => 'android'], $jeton);
        self::assertResponseStatusCodeSame(204);
    }

    /** @return array<string, mixed> */
    private function chambre(int $prix): array
    {
        return [
            'titre' => 'Chambre à Bè', 'description' => 'Chambre propre', 'typeBien' => 'chambre',
            'typeTransaction' => 'location', 'prix' => $prix, 'region' => 'maritime', 'ville' => 'Lomé', 'quartier' => 'Bè',
        ];
    }
}
