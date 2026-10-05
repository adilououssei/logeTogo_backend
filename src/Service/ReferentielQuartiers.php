<?php

namespace App\Service;

use App\Entity\QuartierAjoute;
use App\Entity\Utilisateur;
use App\Enum\Region;
use App\Repository\QuartierAjouteRepository;
use App\Service\AnnonceVocale\LieuxTogo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quartiers connus : la liste officielle (config/lieux/quartiers_togo.csv) et ceux que les
 * agents ont ajoutés en publiant une annonce dans un quartier absent de la liste.
 *
 * @phpstan-import-type Quartier from LieuxTogo
 */
class ReferentielQuartiers
{
    public function __construct(
        private readonly QuartierAjouteRepository $quartiersAjoutes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Quartiers ajoutés par les agents, au format de la liste officielle.
     *
     * @return list<Quartier>
     */
    public function ajoutes(): array
    {
        return array_map(
            fn (QuartierAjoute $q) => ['nom' => (string) $q->getNom(), 'ville' => (string) $q->getVille(), 'variantes' => [], 'prioritaire' => false],
            $this->quartiersAjoutes->findAll(),
        );
    }

    /**
     * @return list<array{nom: string, ville: string, region: ?Region}>
     */
    public function suggerer(string $debut, ?string $ville = null, int $limite = 8): array
    {
        return LieuxTogo::suggerer($debut, $this->ajoutes(), $ville, $limite);
    }

    /**
     * Nom officiel du quartier saisi (« agoe assiyeye » → « Agoè Assiyéyé »). Un quartier
     * inconnu est ajouté à la liste (l'appelant enregistre) et gardé tel que l'agent l'a écrit.
     */
    public function nomOfficielOuAjout(string $quartier, string $ville, ?Region $region, ?Utilisateur $agent): string
    {
        $quartier = trim((string) preg_replace('/\s+/u', ' ', $quartier));
        $ajoutes = $this->ajoutes();
        $connu = LieuxTogo::reconnaitre($quartier, $ajoutes);
        if (null !== $connu) {
            return $connu['nom'];
        }
        $normalise = LieuxTogo::normaliser($quartier);
        // Un nom de ville (« Lomé ») ou trop court n'est pas un quartier à retenir.
        $estVille = \in_array($normalise, array_map(LieuxTogo::normaliser(...), array_keys(LieuxTogo::VILLES)), true);
        if (null !== $region && mb_strlen($normalise) >= 3 && !$estVille) {
            $this->em->persist((new QuartierAjoute())
                ->setNom($quartier)
                ->setNomNormalise($normalise)
                ->setVille(trim($ville))
                ->setRegion($region)
                ->setAjoutePar($agent));
        }

        return $quartier;
    }
}
