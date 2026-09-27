<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927092806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial LogeTogo : utilisateurs, annonces, médias, favoris, messagerie, notifications, avis, alertes de recherche';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alerte_recherche (id INT AUTO_INCREMENT NOT NULL, region VARCHAR(20) DEFAULT NULL, quartier VARCHAR(100) DEFAULT NULL, type_bien VARCHAR(20) DEFAULT NULL, type_transaction VARCHAR(20) DEFAULT NULL, prix_max BIGINT DEFAULT NULL, est_active TINYINT NOT NULL, date_creation DATETIME NOT NULL, utilisateur_id INT NOT NULL, INDEX idx_alerte_active_region (est_active, region), INDEX IDX_B271C113FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE annonce (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, description LONGTEXT NOT NULL, type_bien VARCHAR(20) NOT NULL, type_transaction VARCHAR(20) NOT NULL, prix BIGINT NOT NULL, avance_mois SMALLINT DEFAULT NULL, caution_mois SMALLINT DEFAULT NULL, commission BIGINT DEFAULT NULL, chambres SMALLINT NOT NULL, salles_de_bain SMALLINT NOT NULL, superficie INT DEFAULT NULL, equipements JSON NOT NULL, statut VARCHAR(20) NOT NULL, nombre_vues INT NOT NULL, date_publication DATETIME NOT NULL, date_modification DATETIME DEFAULT NULL, localisation_region VARCHAR(20) NOT NULL, localisation_ville VARCHAR(100) NOT NULL, localisation_quartier VARCHAR(100) NOT NULL, localisation_adresse VARCHAR(255) DEFAULT NULL, localisation_latitude DOUBLE PRECISION DEFAULT NULL, localisation_longitude DOUBLE PRECISION DEFAULT NULL, contact_telephone VARCHAR(30) DEFAULT NULL, contact_whatsapp VARCHAR(30) DEFAULT NULL, publie_par_id INT NOT NULL, INDEX idx_annonce_region_statut (localisation_region, statut), INDEX idx_annonce_date_publication (date_publication), INDEX IDX_F65593E5801A2092 (publie_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE avis (id INT AUTO_INCREMENT NOT NULL, note SMALLINT NOT NULL, commentaire LONGTEXT DEFAULT NULL, est_masque TINYINT NOT NULL, date_creation DATETIME NOT NULL, auteur_id INT NOT NULL, annonce_id INT DEFAULT NULL, agent_evalue_id INT DEFAULT NULL, UNIQUE INDEX uniq_avis_auteur_annonce (auteur_id, annonce_id), UNIQUE INDEX uniq_avis_auteur_agent (auteur_id, agent_evalue_id), INDEX IDX_8F91ABF060BB6FE6 (auteur_id), INDEX IDX_8F91ABF08805AB2F (annonce_id), INDEX IDX_8F91ABF09BAB32CA (agent_evalue_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation (id INT AUTO_INCREMENT NOT NULL, date_creation DATETIME NOT NULL, date_dernier_message DATETIME DEFAULT NULL, annonce_id INT DEFAULT NULL, demandeur_id INT NOT NULL, annonceur_id INT NOT NULL, INDEX idx_conversation_dernier_message (date_dernier_message), UNIQUE INDEX uniq_conversation_annonce_participants (annonce_id, demandeur_id, annonceur_id), INDEX IDX_8A8E26E98805AB2F (annonce_id), INDEX IDX_8A8E26E995A6EE59 (demandeur_id), INDEX IDX_8A8E26E9C8764012 (annonceur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE favori (id INT AUTO_INCREMENT NOT NULL, date_ajout DATETIME NOT NULL, utilisateur_id INT NOT NULL, annonce_id INT NOT NULL, UNIQUE INDEX uniq_favori_utilisateur_annonce (utilisateur_id, annonce_id), INDEX IDX_EF85A2CCFB88E14F (utilisateur_id), INDEX IDX_EF85A2CC8805AB2F (annonce_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(500) NOT NULL, type VARCHAR(10) NOT NULL, ordre SMALLINT NOT NULL, annonce_id INT NOT NULL, INDEX IDX_6A2CA10C8805AB2F (annonce_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, contenu LONGTEXT DEFAULT NULL, url_fichier VARCHAR(500) DEFAULT NULL, duree_vocal SMALLINT DEFAULT NULL, est_lu TINYINT NOT NULL, date_envoi DATETIME NOT NULL, conversation_id INT NOT NULL, expediteur_id INT NOT NULL, INDEX idx_message_conversation_date (conversation_id, date_envoi), INDEX IDX_B6BD307F9AC0396 (conversation_id), INDEX IDX_B6BD307F10335F61 (expediteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, titre VARCHAR(150) NOT NULL, contenu LONGTEXT NOT NULL, est_lue TINYINT NOT NULL, date_creation DATETIME NOT NULL, destinataire_id INT NOT NULL, annonce_id INT DEFAULT NULL, INDEX idx_notification_destinataire_lue (destinataire_id, est_lue), INDEX IDX_BF5476CAA4F84F6E (destinataire_id), INDEX IDX_BF5476CA8805AB2F (annonce_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, identifiant VARCHAR(80) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, indicatif_pays VARCHAR(6) DEFAULT NULL, region VARCHAR(20) NOT NULL, role VARCHAR(20) NOT NULL, mot_de_passe VARCHAR(255) NOT NULL, avatar VARCHAR(500) DEFAULT NULL, verifie TINYINT NOT NULL, est_actif TINYINT NOT NULL, date_inscription DATETIME NOT NULL, UNIQUE INDEX UNIQ_1D1C63B3C90409EC (identifiant), UNIQUE INDEX UNIQ_1D1C63B3E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE alerte_recherche ADD CONSTRAINT FK_B271C113FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE annonce ADD CONSTRAINT FK_F65593E5801A2092 FOREIGN KEY (publie_par_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF060BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF08805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF09BAB32CA FOREIGN KEY (agent_evalue_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E98805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E995A6EE59 FOREIGN KEY (demandeur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9C8764012 FOREIGN KEY (annonceur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favori ADD CONSTRAINT FK_EF85A2CCFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE favori ADD CONSTRAINT FK_EF85A2CC8805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C8805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F10335F61 FOREIGN KEY (expediteur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA4F84F6E FOREIGN KEY (destinataire_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA8805AB2F FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alerte_recherche DROP FOREIGN KEY FK_B271C113FB88E14F');
        $this->addSql('ALTER TABLE annonce DROP FOREIGN KEY FK_F65593E5801A2092');
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY FK_8F91ABF060BB6FE6');
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY FK_8F91ABF08805AB2F');
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY FK_8F91ABF09BAB32CA');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E98805AB2F');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E995A6EE59');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_8A8E26E9C8764012');
        $this->addSql('ALTER TABLE favori DROP FOREIGN KEY FK_EF85A2CCFB88E14F');
        $this->addSql('ALTER TABLE favori DROP FOREIGN KEY FK_EF85A2CC8805AB2F');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C8805AB2F');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F9AC0396');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307F10335F61');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA4F84F6E');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA8805AB2F');
        $this->addSql('DROP TABLE alerte_recherche');
        $this->addSql('DROP TABLE annonce');
        $this->addSql('DROP TABLE avis');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE favori');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE message');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
