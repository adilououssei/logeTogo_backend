<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005185148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi de la disponibilité des annonces : date de dernière confirmation et rappel en cours.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE annonce ADD date_confirmation DATETIME DEFAULT NULL, ADD date_rappel_disponibilite DATETIME DEFAULT NULL');
        // Annonces existantes : le délai de rappel compte depuis leur publication.
        $this->addSql('UPDATE annonce SET date_confirmation = date_publication');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE annonce DROP date_confirmation, DROP date_rappel_disponibilite');
    }
}
