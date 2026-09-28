<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927152042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE code_reinitialisation (id INT AUTO_INCREMENT NOT NULL, empreinte_code VARCHAR(255) NOT NULL, essais_manques INT NOT NULL, date_creation DATETIME NOT NULL, date_expiration DATETIME NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_3E59396BFB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE code_reinitialisation ADD CONSTRAINT FK_3E59396BFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE code_reinitialisation DROP FOREIGN KEY FK_3E59396BFB88E14F');
        $this->addSql('DROP TABLE code_reinitialisation');
    }
}
