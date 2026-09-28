<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928182142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demande_verification (id INT AUTO_INCREMENT NOT NULL, type_document VARCHAR(20) NOT NULL, recto VARCHAR(255) DEFAULT NULL, verso VARCHAR(255) DEFAULT NULL, statut VARCHAR(20) NOT NULL, motif_refus VARCHAR(255) DEFAULT NULL, date_demande DATETIME NOT NULL, date_decision DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, INDEX IDX_D385771FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_verification ADD CONSTRAINT FK_D385771FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur ADD alertes_email TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demande_verification DROP FOREIGN KEY FK_D385771FB88E14F');
        $this->addSql('DROP TABLE demande_verification');
        $this->addSql('ALTER TABLE utilisateur DROP alertes_email');
    }
}
