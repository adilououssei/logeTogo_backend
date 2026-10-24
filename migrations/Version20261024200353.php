<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261024200353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connexion avec Google : compte Google lié à l\'utilisateur.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE utilisateur ADD google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D1C63B376F5C865 ON utilisateur (google_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1D1C63B376F5C865 ON utilisateur');
        $this->addSql('ALTER TABLE utilisateur DROP google_id');
    }
}
