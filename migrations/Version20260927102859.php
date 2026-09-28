<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927102859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons de renouvellement (session longue durée de l\'application mobile)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE jeton_renouvellement (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, family VARCHAR(32) DEFAULT NULL, family_valid DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_CE43FABBC74F2195 (refresh_token), INDEX idx_jeton_renouvellement_famille (family), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE jeton_renouvellement');
    }
}
