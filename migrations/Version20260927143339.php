<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927143339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications push : appareils (jetons Expo) et lien notification → conversation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE appareil (id INT AUTO_INCREMENT NOT NULL, jeton_push VARCHAR(255) NOT NULL, plateforme VARCHAR(10) NOT NULL, date_enregistrement DATETIME NOT NULL, utilisateur_id INT NOT NULL, UNIQUE INDEX UNIQ_456A601A909C640A (jeton_push), INDEX IDX_456A601AFB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appareil ADD CONSTRAINT FK_456A601AFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD conversation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_BF5476CA9AC0396 ON notification (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE appareil DROP FOREIGN KEY FK_456A601AFB88E14F');
        $this->addSql('DROP TABLE appareil');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA9AC0396');
        $this->addSql('DROP INDEX IDX_BF5476CA9AC0396 ON notification');
        $this->addSql('ALTER TABLE notification DROP conversation_id');
    }
}
