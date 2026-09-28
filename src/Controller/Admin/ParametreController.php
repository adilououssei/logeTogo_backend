<?php

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\GenerateurIdentifiant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Paramètres : mot de passe de l'administrateur, liste et ajout d'administrateurs, état de la plateforme. */
#[Route('/admin/parametres', name: 'admin_')]
class ParametreController extends AbstractController
{
    use JetonAdminTrait;

    #[Route('', name: 'parametres', methods: ['GET'])]
    public function index(
        UtilisateurRepository $utilisateurs,
        #[Autowire('%env(MAILER_DSN)%')] string $mailerDsn,
        #[Autowire('%env(MAILER_EXPEDITEUR)%')] string $expediteur,
        #[Autowire('%kernel.environment%')] string $environnement,
    ): Response {
        return $this->render('admin/parametres.html.twig', [
            'administrateurs' => $utilisateurs->findBy(['role' => RoleUtilisateur::ADMIN], ['dateInscription' => 'ASC']),
            'plateforme' => [
                'emailsActifs' => !str_starts_with($mailerDsn, 'null://'),
                'expediteur' => $expediteur,
                'environnement' => $environnement,
                'php' => \PHP_VERSION,
                'symfony' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            ],
        ]);
    }

    #[Route('/mot-de-passe', name: 'parametres_mot_de_passe', methods: ['POST'])]
    public function motDePasse(Request $requete, #[CurrentUser] Utilisateur $admin, UserPasswordHasherInterface $hacheur, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($requete);
        $actuel = (string) $requete->request->get('actuel');
        $nouveau = (string) $requete->request->get('nouveau');
        if (!$hacheur->isPasswordValid($admin, $actuel)) {
            $this->addFlash('erreur', 'Mot de passe actuel incorrect.');
        } elseif (mb_strlen($nouveau) < 8) {
            $this->addFlash('erreur', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
        } elseif ($nouveau !== $requete->request->get('confirmation')) {
            $this->addFlash('erreur', 'Les deux mots de passe ne correspondent pas.');
        } else {
            $admin->setMotDePasse($hacheur->hashPassword($admin, $nouveau));
            $em->flush();
            $this->addFlash('succes', 'Mot de passe modifié.');
        }

        return $this->redirectToRoute('admin_parametres');
    }

    /** Nouvel administrateur : identifiant généré comme pour les autres comptes (prenom.nom). */
    #[Route('/administrateurs', name: 'parametres_ajouter_admin', methods: ['POST'])]
    public function ajouterAdministrateur(Request $requete, GenerateurIdentifiant $generateur, UserPasswordHasherInterface $hacheur, EntityManagerInterface $em): Response
    {
        $this->verifierJeton($requete);
        $prenom = trim((string) $requete->request->get('prenom'));
        $nom = trim((string) $requete->request->get('nom'));
        $email = mb_strtolower(trim((string) $requete->request->get('email'))) ?: null;
        $motDePasse = (string) $requete->request->get('motDePasse');

        if ('' === $prenom || '' === $nom || mb_strlen($motDePasse) < 8) {
            $this->addFlash('erreur', 'Prénom, nom et mot de passe (8 caractères au moins) sont obligatoires.');

            return $this->redirectToRoute('admin_parametres');
        }
        if (null !== $email && null !== $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email])) {
            $this->addFlash('erreur', 'Cet email est déjà utilisé.');

            return $this->redirectToRoute('admin_parametres');
        }

        $admin = (new Utilisateur())
            ->setIdentifiant($generateur->generer($prenom, $nom))
            ->setPrenom($prenom)
            ->setNom($nom)
            ->setEmail($email)
            ->setRegion(Region::MARITIME)
            ->setRole(RoleUtilisateur::ADMIN);
        $admin->setMotDePasse($hacheur->hashPassword($admin, $motDePasse));
        $em->persist($admin);
        $em->flush();
        $this->addFlash('succes', \sprintf('Administrateur créé. Identifiant de connexion : %s', $admin->getIdentifiant()));

        return $this->redirectToRoute('admin_parametres');
    }
}
