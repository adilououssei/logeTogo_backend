<?php

namespace App\Command;

use App\Entity\Annonce;
use App\Entity\Media;
use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeBien;
use App\Enum\TypeTransaction;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Charge les annonces de démonstration (les mêmes que l'ancienne maquette de l'application)
 * avec leurs trois agents. N'ajoute que ce qui manque : sans risque si on la relance.
 *
 *   php bin/console app:charger-donnees-demo
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:charger-donnees-demo', description: 'Ajoute 3 agents et 6 annonces de démonstration (développement uniquement)')]
final class ChargerDonneesDemoCommand
{
    public const MOT_DE_PASSE_DEMO = 'demo1234';

    private const AGENTS = [
        'demo.kofi' => ['Kofi', 'Mensah', '+228 90 00 00 01', 'https://i.pravatar.cc/100?img=1', true],
        'demo.ama' => ['Ama', 'Koffi', '+228 90 00 00 02', 'https://i.pravatar.cc/100?img=5', true],
        'demo.edem' => ['Edem', 'Agbo', '+228 90 00 00 03', 'https://i.pravatar.cc/100?img=8', false],
    ];

    private const ANNONCES = [
        ['demo.kofi', 'Chambre meublée à Adidogomé', TypeBien::CHAMBRE, TypeTransaction::LOCATION, 25000, 6, 3, 25000, 1, 1, 15,
            'Belle chambre meublée avec climatisation, salle de bain privée et accès WiFi. Proche du campus universitaire de Lomé.',
            ['WiFi', 'Climatisation', 'Salle de bain privée', 'Cuisine partagée'], Region::MARITIME, 'Lomé', 'Adidogomé', 6.1726, 1.2312,
            ['photo-1522708323590-d24dbb6b0267', 'photo-1555854877-bab0e564b8d5']],
        ['demo.ama', 'Studio moderne à Bè', TypeBien::STUDIO, TypeTransaction::LOCATION, 45000, 10, 6, 45000, 1, 1, 30,
            'Studio entièrement rénové avec salon, cuisine équipée et salle de bain moderne. Quartier animé.',
            ['WiFi', 'Cuisine équipée', 'Parking', 'Sécurité 24/7'], Region::MARITIME, 'Lomé', 'Bè', 6.1400, 1.2450,
            ['photo-1502672260266-1c1ef2d93688', 'photo-1560448204-e02f11c3d0e2']],
        ['demo.edem', 'Appartement F2 à Tokoin', TypeBien::APPARTEMENT, TypeTransaction::LOCATION, 75000, 12, 6, 75000, 2, 1, 55,
            'Bel appartement F2 avec terrasse, vue dégagée, quartier calme et sécurisé.',
            ['WiFi', 'Terrasse', 'Parking', 'Climatisation', 'Sécurité'], Region::MARITIME, 'Lomé', 'Tokoin', 6.1528, 1.2176,
            ['photo-1493809842364-78817add7ffb', 'photo-1484101403633-562f891dc89a']],
        ['demo.kofi', 'Terrain à bâtir à Kpalimé', TypeBien::TERRAIN, TypeTransaction::VENTE, 8500000, null, null, null, 0, 0, 500,
            'Beau terrain de 500m² avec titre foncier. Quartier en plein développement, accès facile.',
            ['Titre foncier', 'Borné', 'Accès route principale'], Region::PLATEAUX, 'Kpalimé', 'Kpalimé', 6.9000, 0.6300,
            ['photo-1500382017468-9049fed747ef']],
        ['demo.ama', 'Villa 3 chambres à Agoè', TypeBien::VILLA, TypeTransaction::LOCATION, 150000, 12, 6, 150000, 3, 2, 120,
            'Magnifique villa avec jardin privatif, garage et résidence sécurisée.',
            ['Jardin', 'Garage', 'Climatisation', 'Sécurité 24/7', 'WiFi'], Region::MARITIME, 'Lomé', 'Agoè', 6.2100, 1.2234,
            ['photo-1580587771525-78b9dba3b914', 'photo-1512917774080-9991f1c4c750']],
        ['demo.edem', 'Maison à vendre à Kara', TypeBien::MAISON, TypeTransaction::VENTE, 25000000, null, null, null, 4, 2, 200,
            'Belle maison de 4 pièces à vendre. Titre foncier disponible. Quartier calme et sécurisé.',
            ['Titre foncier', 'Eau courante', 'Électricité', 'Jardin', 'Garage'], Region::KARA, 'Kara', 'Tomdè', 9.5511, 1.1861,
            ['photo-1564013799919-ab600027ffc6']],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $agents = [];
        foreach (self::AGENTS as $identifiant => [$prenom, $nom, $telephone, $avatar, $verifie]) {
            $agent = $this->utilisateurs->findOneBy(['identifiant' => $identifiant]);
            if (null === $agent) {
                $agent = (new Utilisateur())
                    ->setIdentifiant($identifiant)->setPrenom($prenom)->setNom($nom)
                    ->setRegion(Region::MARITIME)->setRole(RoleUtilisateur::AGENT)
                    ->setTelephone($telephone)->setIndicatifPays('+228')
                    ->setAvatar($avatar)->setVerifie($verifie);
                $agent->setMotDePasse($this->hacheur->hashPassword($agent, self::MOT_DE_PASSE_DEMO));
                $this->em->persist($agent);
                $io->writeln("Agent créé : <info>$identifiant</info>");
            }
            $agents[$identifiant] = $agent;
        }

        $ajoutees = 0;
        foreach (self::ANNONCES as [$agent, $titre, $type, $transaction, $prix, $avance, $caution, $commission, $chambres, $sdb, $superficie, $description, $equipements, $region, $ville, $quartier, $lat, $lng, $photos]) {
            if (null !== $this->em->getRepository(Annonce::class)->findOneBy(['titre' => $titre, 'publiePar' => $agents[$agent]->getId()])) {
                continue;
            }
            $annonce = (new Annonce())
                ->setTitre($titre)->setDescription($description)->setTypeBien($type)->setTypeTransaction($transaction)
                ->setPrix($prix)->setAvanceMois($avance)->setCautionMois($caution)->setCommission($commission)
                ->setChambres($chambres)->setSallesDeBain($sdb)->setSuperficie($superficie)->setEquipements($equipements);
            $annonce->getLocalisation()->setRegion($region)->setVille($ville)->setQuartier($quartier)->setLatitude($lat)->setLongitude($lng);
            foreach ($photos as $ordre => $photo) {
                $annonce->addMedia((new Media())->setUrl("https://images.unsplash.com/$photo?w=800")->setOrdre($ordre));
            }
            $agents[$agent]->addAnnonce($annonce);
            $this->em->persist($annonce);
            ++$ajoutees;
        }

        $this->em->flush();
        $io->success(\sprintf('%d annonce(s) de démonstration ajoutée(s). Comptes agents : %s — mot de passe : %s', $ajoutees, implode(', ', array_keys(self::AGENTS)), self::MOT_DE_PASSE_DEMO));

        return Command::SUCCESS;
    }
}
