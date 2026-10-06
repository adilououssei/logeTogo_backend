<?php

namespace App\Tests\Controller;

use App\Entity\Annonce;
use App\Entity\Notification;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Enum\TypeNotification;
use App\Enum\TypeTransaction;
use App\Service\SuiviDisponibilite;
use App\Tests\OutilsApi;
use App\Tests\ServeurExpoSimule;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Biens loués ou vendus retirés du public : rappels, retraits, confirmations, signalements. */
final class DisponibiliteTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
        ServeurExpoSimule::vider();
    }

    public function testRappelAuSeptiemeJourPuisRetraitSansReponse(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent, ['titre' => 'Chambre salon à Agoè']);
        $recente = $this->creerAnnonce($agent, ['titre' => 'Publiée hier']);
        $this->confirmeeIlYA($annonce, 8);
        $this->confirmeeIlYA($recente, 1);

        // 7e jour : rappel à l'annonceur (notification + push), l'annonce reste en ligne.
        self::assertSame(['rappels' => 1, 'retraits' => 0], $this->suivi()->verifier());
        $annonce = $this->recharger($annonce);
        self::assertNotNull($annonce->getDateRappelDisponibilite());
        self::assertSame(StatutAnnonce::DISPONIBLE, $annonce->getStatut());
        self::assertSame(['Votre bien est-il toujours disponible ?'], $this->titresNotifications(TypeNotification::DISPONIBILITE));
        self::assertTrue($this->rappelDe($agent, 'Chambre salon à Agoè'), 'rappel visible dans le tableau de bord');

        // Le même jour, pas de second rappel.
        self::assertSame(['rappels' => 0, 'retraits' => 0], $this->suivi()->verifier());

        // 7 jours plus tard sans réponse : retirée du public, en attente de confirmation.
        // (L'annonce publiée hier atteint à son tour son 7e jour : elle reçoit son rappel.)
        self::assertSame(['rappels' => 1, 'retraits' => 1], $this->suivi()->verifier(new \DateTimeImmutable('+8 days')));
        self::assertSame(StatutAnnonce::A_CONFIRMER, $this->recharger($annonce)->getStatut());
        self::assertSame(['Publiée hier'], array_column($this->requeteJson('GET', '/api/annonces')['elements'], 'titre'));
        self::assertContains('Annonce retirée en attendant votre confirmation', $this->titresNotifications(TypeNotification::DISPONIBILITE));
    }

    public function testToujoursDisponibleRemetEnLigneEtRelanceLeDelai(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent, ['statut' => StatutAnnonce::A_CONFIRMER]);
        $this->confirmeeIlYA($annonce, 20);
        $url = '/api/annonces/'.$annonce->getId().'/confirmer-disponibilite';

        $this->requeteJson('POST', $url, jeton: $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertResponseStatusCodeSame(403, 'seul l\'annonceur confirme');

        $reponse = $this->requeteJson('POST', $url, jeton: $this->jetonPour($agent));
        self::assertResponseIsSuccessful();
        self::assertSame('disponible', $reponse['statut']);
        self::assertFalse($reponse['rappelEnAttente']);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $this->recharger($annonce)->getDateConfirmation());
        self::assertSame(['rappels' => 0, 'retraits' => 0], $this->suivi()->verifier(new \DateTimeImmutable('+6 days')), 'prochain rappel dans 7 jours');
    }

    public function testMarquerLoueOuVenduPuisRemettreEnLigne(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $location = $this->creerAnnonce($agent);
        $this->confirmeeIlYA($location, 30);
        $url = '/api/annonces/'.$location->getId().'/statut';

        self::assertSame('occupe', $this->requeteJson('PATCH', $url, ['statut' => 'occupe'], $this->jetonPour($agent))['statut']);
        self::assertSame([], $this->requeteJson('GET', '/api/annonces')['elements']);

        // Remise en ligne quand le bien se libère : vaut confirmation.
        self::assertSame('disponible', $this->requeteJson('PATCH', $url, ['statut' => 'disponible'], $this->jetonPour($agent))['statut']);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $this->recharger($location)->getDateConfirmation());

        $this->requeteJson('PATCH', $url, ['statut' => 'a_confirmer'], $this->jetonPour($agent));
        self::assertResponseStatusCodeSame(403, '« à confirmer » est réservé au suivi automatique');
    }

    public function testModifierLAnnonceVautConfirmation(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $this->confirmeeIlYA($annonce, 6);

        $this->requeteJson('PUT', '/api/annonces/'.$annonce->getId(), [
            'titre' => 'Chambre rénovée', 'description' => 'Peinture neuve.', 'typeBien' => 'chambre', 'typeTransaction' => 'location',
            'prix' => 30000, 'avanceMois' => 6, 'chambres' => 1, 'sallesDeBain' => 1,
            'region' => 'maritime', 'ville' => 'Lomé', 'quartier' => 'Tokoin',
        ], $this->jetonPour($agent));

        self::assertResponseIsSuccessful();
        self::assertSame(['rappels' => 0, 'retraits' => 0], $this->suivi()->verifier(new \DateTimeImmutable('+2 days')));
    }

    public function testPlusieursSignalementsDejaLoueRetirentLAnnonce(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $url = '/api/annonces/'.$annonce->getId().'/signalements';

        $this->requeteJson('POST', $url, ['motif' => 'deja_loue'], $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut(), 'un seul signalement ne suffit pas');

        $this->requeteJson('POST', $url, ['motif' => 'deja_loue'], $this->jetonPour($this->creerUtilisateur('edem.agbo')));
        self::assertSame(StatutAnnonce::A_CONFIRMER, $this->recharger($annonce)->getStatut());
        self::assertContains('Annonce retirée en attendant votre confirmation', $this->titresNotifications(TypeNotification::DISPONIBILITE));

        // L'annonceur confirme : les signalements « déjà loué » sont clos et le bien revient en ligne.
        $this->requeteJson('POST', '/api/annonces/'.$annonce->getId().'/confirmer-disponibilite', jeton: $this->jetonPour($agent));
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut());
    }

    public function testOnNeContactePlusPourUnBienPris(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $locataire = $this->creerUtilisateur('ama.koffi');
        $annonce = $this->creerAnnonce($agent);
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $this->jetonPour($locataire));
        self::assertResponseStatusCodeSame(201);

        $vendue = $this->creerAnnonce($agent, ['statut' => StatutAnnonce::OCCUPE]);
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $vendue->getId()], $this->jetonPour($locataire));
        self::assertResponseStatusCodeSame(422);

        // Une conversation déjà commencée reste accessible après la location.
        $this->em()->getConnection()->executeStatement('UPDATE annonce SET statut = ? WHERE id = ?', ['occupe', $annonce->getId()]);
        $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $this->jetonPour($locataire));
        self::assertResponseStatusCodeSame(200);
    }

    public function testReponseDepuisLEmailSansConnexion(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent, ['typeTransaction' => TypeTransaction::VENTE]);
        $lien = $this->suivi()->lienEmail($annonce, 'pris');

        // Ouvrir le lien ne change rien : une page demande confirmation.
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('button', 'Oui, déjà vendu');
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut());

        $this->client->request('POST', $lien);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutAnnonce::VENDU, $this->recharger($annonce)->getStatut());

        // Lien modifié ou non signé : refusé.
        $this->client->request('GET', '/disponibilite/'.$annonce->getId().'/disponible');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRappelInvisibleDesVisiteurs(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);

        $detail = $this->requeteJson('GET', '/api/annonces/'.$annonce->getId(), jeton: $this->jetonPour($this->creerUtilisateur('ama.koffi')));
        self::assertArrayHasKey('dateConfirmation', $detail, 'affichée aux visiteurs : « disponibilité confirmée il y a… »');
        self::assertArrayNotHasKey('rappelEnAttente', $detail);
    }

    private function suivi(): SuiviDisponibilite
    {
        return static::getContainer()->get(SuiviDisponibilite::class);
    }

    private function confirmeeIlYA(Annonce $annonce, int $jours): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE annonce SET date_confirmation = ? WHERE id = ?',
            [(new \DateTimeImmutable("-$jours days"))->format('Y-m-d H:i:s'), $annonce->getId()],
        );
        $this->em()->clear();
    }

    private function recharger(Annonce $annonce): Annonce
    {
        $this->em()->clear();

        return $this->em()->find(Annonce::class, $annonce->getId());
    }

    /** @return list<string> */
    private function titresNotifications(TypeNotification $type): array
    {
        return array_map(fn (Notification $n) => $n->getTitre(), $this->em()->getRepository(Notification::class)->findBy(['type' => $type], ['id' => 'ASC']));
    }

    private function rappelDe(Utilisateur $agent, string $titre): bool
    {
        foreach ($this->requeteJson('GET', '/api/annonces/mes-annonces', jeton: $this->jetonPour($agent)) as $a) {
            if ($a['titre'] === $titre) {
                return $a['rappelEnAttente'];
            }
        }

        return false;
    }
}
