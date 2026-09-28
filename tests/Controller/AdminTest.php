<?php

namespace App\Tests\Controller;

use App\Entity\Annonce;
use App\Entity\DemandeVerification;
use App\Entity\Signalement;
use App\Entity\Utilisateur;
use App\Enum\MotifSignalement;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutAnnonce;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/verifications');
        parent::tearDown();
    }

    public function testConnexionParFormulaireReserveeAuxAdministrateurs(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/connexion');

        $this->avecMotDePasse($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT), 'secret123');
        $this->seConnecter('kofi.mensah', 'secret123');
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/connexion', message: 'un agent est renvoyé vers la connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.message--erreur', 'réservé aux administrateurs');

        $this->avecMotDePasse($this->creerUtilisateur('chef.admin', RoleUtilisateur::ADMIN), 'motdepasse-admin');
        $this->seConnecter('chef.admin', 'faux');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.message--erreur', 'Identifiant ou mot de passe incorrect');

        $this->seConnecter('chef.admin', 'motdepasse-admin');
        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Chef');
    }

    public function testToutesLesPagesSAffichent(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $locataire = $this->creerUtilisateur('ama.koffi');
        $this->em()->persist(new Signalement($annonce, $locataire, MotifSignalement::ARNAQUE, 'Suspect'));
        $this->em()->flush();
        $this->connecterAdmin();

        foreach (['/admin', '/admin/utilisateurs', '/admin/utilisateurs?role=agent&q=kofi', '/admin/agents', '/admin/biens', '/admin/biens?statut=disponible&type=chambre',
            '/admin/biens/'.$annonce->getId(), '/admin/utilisateurs/'.$agent->getId(), '/admin/signalements', '/admin/signalements?statut=tous',
            '/admin/verifications', '/admin/rapports', '/admin/parametres', '/admin/recherche?q=kofi'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('.pastille-cloche', '1');
        self::assertSelectorTextContains('.grille--bas', 'Chambre meublée à Adidogomé');

        $this->client->request('GET', '/admin/recherche?q=kofi');
        self::assertSelectorTextContains('.contenu', 'Kofi Mensah');

        $this->client->request('GET', '/admin/rapports/export/biens');
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function testSuspendreUneAnnonceSignalee(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $annonce = $this->creerAnnonce($agent);
        $signalement = new Signalement($annonce, $this->creerUtilisateur('ama.koffi'), MotifSignalement::DEJA_LOUE, null);
        $autre = new Signalement($annonce, $this->creerUtilisateur('edem.agbo'), MotifSignalement::ARNAQUE, null);
        $this->em()->persist($signalement);
        $this->em()->persist($autre);
        $this->em()->flush();
        $this->connecterAdmin();

        // Sans jeton de sécurité : refusé.
        $this->client->request('POST', '/admin/signalements/'.$signalement->getId().'/suspendre');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut());
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful('un jeton invalide ne déconnecte pas l\'administrateur');

        $this->envoyer('/admin/signalements', '/admin/signalements/'.$signalement->getId().'/suspendre');
        self::assertResponseRedirects('/admin/signalements');

        self::assertSame(StatutAnnonce::SUSPENDU, $this->recharger($annonce)->getStatut());
        self::assertSame(Signalement::TRAITE, $this->recharger($autre)->getStatut(), 'tous les signalements de l\'annonce sont clos');
        $this->requeteJson('GET', '/api/annonces/'.$annonce->getId());
        self::assertResponseStatusCodeSame(404, 'l\'annonce n\'est plus visible dans l\'application');
        $notifications = $this->requeteJson('GET', '/api/notifications', jeton: $this->jetonPour($agent));
        self::assertSame('Annonce suspendue', $notifications[0]['titre']);

        // Remise en ligne.
        $this->envoyer('/admin/biens/'.$annonce->getId(), '/admin/biens/'.$annonce->getId().'/reactiver');
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut());
    }

    public function testRejeterUnSignalement(): void
    {
        $annonce = $this->creerAnnonce($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT));
        $signalement = new Signalement($annonce, $this->creerUtilisateur('ama.koffi'), MotifSignalement::AUTRE, null);
        $this->em()->persist($signalement);
        $this->em()->flush();
        $this->connecterAdmin();

        $this->envoyer('/admin/signalements', '/admin/signalements/'.$signalement->getId().'/rejeter');

        self::assertSame(Signalement::REJETE, $this->recharger($signalement)->getStatut());
        self::assertSame(StatutAnnonce::DISPONIBLE, $this->recharger($annonce)->getStatut());
    }

    public function testSuspendreEtReactiverUnCompte(): void
    {
        $agent = $this->avecMotDePasse($this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT), 'secret123');
        $this->connecterAdmin();

        $this->envoyer('/admin/utilisateurs', '/admin/utilisateurs/'.$agent->getId().'/etat');
        self::assertFalse($this->recharger($agent)->isEstActif());
        $this->requeteJson('POST', '/api/auth/connexion', ['identifiant' => 'kofi.mensah', 'motDePasse' => 'secret123']);
        self::assertResponseStatusCodeSame(401, 'compte suspendu : plus de connexion à l\'application');

        $this->envoyer('/admin/utilisateurs', '/admin/utilisateurs/'.$agent->getId().'/etat');
        self::assertTrue($this->recharger($agent)->isEstActif());

        $this->envoyer('/admin/agents', '/admin/utilisateurs/'.$agent->getId().'/badge');
        self::assertTrue($this->recharger($agent)->isVerifie());
    }

    public function testExaminerUneVerificationDIdentite(): void
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $dossier = static::getContainer()->getParameter('kernel.project_dir').'/var/verifications/'.$agent->getId();
        (new Filesystem())->dumpFile($dossier.'/recto.png', 'image');
        $demande = (new DemandeVerification($agent, 'cni'))->definirDocuments($agent->getId().'/recto.png', null);
        $this->em()->persist($demande);
        $this->em()->flush();
        $this->connecterAdmin();

        $this->client->request('GET', '/admin/verifications');
        self::assertSelectorTextContains('.contenu', 'Carte nationale d\'identité');
        $this->client->request('GET', '/admin/verifications/'.$demande->getId().'/photo/recto');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'pièce d\'identité jamais mise en cache');

        // Refus sans motif : impossible.
        $this->envoyer('/admin/verifications', '/admin/verifications/'.$demande->getId().'/decision', ['decision' => 'refuser', 'motif' => '']);
        self::assertTrue($this->recharger($demande)->estEnAttente());

        $this->envoyer('/admin/verifications', '/admin/verifications/'.$demande->getId().'/decision', ['decision' => 'approuver']);
        self::assertTrue($this->recharger($agent)->isVerifie());
        self::assertFileDoesNotExist($dossier.'/recto.png');
    }

    public function testAjouterUnAdministrateur(): void
    {
        $this->connecterAdmin();
        $this->envoyer('/admin/parametres', '/admin/parametres/administrateurs', ['prenom' => 'Afi', 'nom' => 'Dogbé', 'email' => '', 'motDePasse' => 'provisoire1']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.message--succes', 'afi.dogbe');

        $nouveau = $this->em()->getRepository(Utilisateur::class)->findOneBy(['identifiant' => 'afi.dogbe']);
        self::assertSame(RoleUtilisateur::ADMIN, $nouveau->getRole());
    }

    private function connecterAdmin(): Utilisateur
    {
        $admin = $this->creerUtilisateur('chef.admin', RoleUtilisateur::ADMIN);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    /** Soumet un formulaire d'action avec un jeton CSRF valide (lu dans la page d'origine). */
    private function envoyer(string $page, string $action, array $champs = []): void
    {
        $crawler = $this->client->request('GET', $page);
        $jeton = $crawler->filter('input[name="_jeton"]')->first()->attr('value');
        $this->client->request('POST', $action, ['_jeton' => $jeton] + $champs);
    }

    private function seConnecter(string $identifiant, string $motDePasse): void
    {
        $crawler = $this->client->request('GET', '/admin/connexion');
        $this->client->submit($crawler->selectButton('Se connecter')->form(['identifiant' => $identifiant, 'motDePasse' => $motDePasse]), [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function avecMotDePasse(Utilisateur $utilisateur, string $motDePasse): Utilisateur
    {
        $utilisateur->setMotDePasse(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($utilisateur, $motDePasse));
        $this->em()->flush();

        return $utilisateur;
    }

    /**
     * @template T of object
     *
     * @param T $entite
     *
     * @return T
     */
    private function recharger(object $entite): object
    {
        $this->em()->clear();

        return $this->em()->find($entite::class, $entite->getId());
    }
}
