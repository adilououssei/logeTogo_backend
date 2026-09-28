<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928203032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE signalement (id INT AUTO_INCREMENT NOT NULL, motif VARCHAR(30) NOT NULL, commentaire LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, date_creation DATETIME NOT NULL, date_traitement DATETIME DEFAULT NULL, annonce_id INT NOT NULL, auteur_id INT NOT NULL, INDEX IDX_F4B55114E564F0BF (statut), INDEX IDX_F4B551148805AB2F (annonce_id), INDEX IDX_F4B5511460BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE signalement ADD CONSTRAINT FK_F4B551148805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signalement ADD CONSTRAINT FK_F4B5511460BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE signalement DROP FOREIGN KEY FK_F4B551148805AB2F');
        $this->addSql('ALTER TABLE signalement DROP FOREIGN KEY FK_F4B5511460BB6FE6');
        $this->addSql('DROP TABLE signalement');
    }
}
