<?php

namespace App\Tests\Controller;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Tests\OutilsApi;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SuppressionCompteTest extends WebTestCase
{
    use OutilsApi;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->viderBase();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('app.dossier_public').'/uploads');
        parent::tearDown();
    }

    public function testMauvaisMotDePasseNeSupprimeRien(): void
    {
        $agent = $this->agentAvecMotDePasse();
        $this->creerAnnonce($agent);

        $reponse = $this->requeteJson('POST', '/api/profil/supprimer', ['motDePasse' => 'faux'], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Mot de passe incorrect.', $reponse['violations'][0]['title']);
        self::assertSame('motDePasse', $reponse['violations'][0]['propertyPath']);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Utilisateur::class, $agent->getId()));
        self::assertSame(1, $this->em()->getRepository(Annonce::class)->count([]));
    }

    public function testLeCompteSesAnnoncesEtSesFichiersSontSupprimes(): void
    {
        $agent = $this->agentAvecMotDePasse();
        $annonce = $this->creerAnnonce($agent);
        $idAgent = $agent->getId();
        $locataire = $this->creerUtilisateur('ama.koffi');
        $jetonLocataire = $this->jetonPour($locataire);

        // Un autre utilisateur interagit avec le compte : favori et conversation.
        $this->requeteJson('PUT', '/api/favoris/'.$annonce->getId(), jeton: $jetonLocataire);
        $conversation = $this->requeteJson('POST', '/api/conversations', ['annonceId' => $annonce->getId()], $jetonLocataire);
        $this->requeteJson('POST', '/api/conversations/'.$conversation['id'].'/messages', ['contenu' => 'Bonjour'], $jetonLocataire);
        self::assertResponseStatusCodeSame(201);

        // Les fichiers du compte, rangés comme le fait l'application.
        $racine = static::getContainer()->getParameter('app.dossier_public');
        $fichiers = [
            \sprintf('%s/uploads/annonces/%d/photo.jpg', $racine, $annonce->getId()),
            \sprintf('%s/uploads/avatars/%d/moi.jpg', $racine, $idAgent),
            \sprintf('%s/uploads/messages/%d/image.jpg', $racine, $conversation['id']),
        ];
        foreach ($fichiers as $fichier) {
            (new Filesystem())->dumpFile($fichier, 'x');
        }
        // Un fichier d'un autre compte, qui doit rester.
        $autre = \sprintf('%s/uploads/avatars/%d/autre.jpg', $racine, $locataire->getId());
        (new Filesystem())->dumpFile($autre, 'x');

        $this->requeteJson('POST', '/api/profil/supprimer', ['motDePasse' => 'secret123'], $this->jetonPour($agent));

        self::assertResponseStatusCodeSame(204);
        $this->em()->clear();
        self::assertNull($this->em()->find(Utilisateur::class, $idAgent));
        self::assertSame(0, $this->em()->getRepository(Annonce::class)->count([]));
        foreach ($fichiers as $fichier) {
            self::assertFileDoesNotExist($fichier);
        }
        self::assertFileExists($autre);

        // Le locataire n'a plus ni favori ni conversation vers ce compte, et l'application continue de marcher.
        self::assertSame([], $this->requeteJson('GET', '/api/favoris', jeton: $jetonLocataire));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->requeteJson('GET', '/api/conversations', jeton: $jetonLocataire));
        self::assertResponseIsSuccessful();
    }

    public function testVisiteurRefuse(): void
    {
        $this->requeteJson('POST', '/api/profil/supprimer', ['motDePasse' => 'secret123']);
        self::assertResponseStatusCodeSame(401);
    }

    private function agentAvecMotDePasse(): Utilisateur
    {
        $agent = $this->creerUtilisateur('kofi.mensah', RoleUtilisateur::AGENT);
        $hacheur = static::getContainer()->get(UserPasswordHasherInterface::class);
        $agent->setMotDePasse($hacheur->hashPassword($agent, 'secret123'));
        $this->em()->flush();

        return $agent;
    }
}
