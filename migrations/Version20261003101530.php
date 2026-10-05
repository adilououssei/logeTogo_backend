<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003101530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quartiers ajoutés par les agents (absents de la liste officielle).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quartier_ajoute (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, nom_normalise VARCHAR(100) NOT NULL, ville VARCHAR(100) NOT NULL, region VARCHAR(255) NOT NULL, date_ajout DATETIME NOT NULL, ajoute_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_467D1708B610943 (nom_normalise), INDEX IDX_467D170DAA76F43 (ajoute_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quartier_ajoute ADD CONSTRAINT FK_467D170DAA76F43 FOREIGN KEY (ajoute_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quartier_ajoute DROP FOREIGN KEY FK_467D170DAA76F43');
        $this->addSql('DROP TABLE quartier_ajoute');
    }
}
