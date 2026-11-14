<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114134138 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, password_setup_token_hash VARCHAR(64) DEFAULT NULL, password_setup_expires_at DATETIME DEFAULT NULL, tenant_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), UNIQUE INDEX UNIQ_88BDF3E9731AF26C (password_setup_token_hash), INDEX IDX_88BDF3E99033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE charge (id INT AUTO_INCREMENT NOT NULL, period DATE NOT NULL, months SMALLINT DEFAULT 1 NOT NULL, net_amount INT NOT NULL, vat_rate NUMERIC(5, 2) NOT NULL, total_amount INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(10) NOT NULL, mollie_payment_id VARCHAR(40) DEFAULT NULL, failed_at DATETIME DEFAULT NULL, failure_reason VARCHAR(255) DEFAULT NULL, reminder_stage SMALLINT NOT NULL, last_reminder_at DATETIME DEFAULT NULL, paid_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id INT NOT NULL, INDEX IDX_556BA4347B00651C (status), UNIQUE INDEX UNIQ_556BA4349033212AC5B81ECE (tenant_id, period), INDEX IDX_556BA4349033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE domain (id INT AUTO_INCREMENT NOT NULL, hostname VARCHAR(253) NOT NULL, is_primary TINYINT NOT NULL, tenant_id INT NOT NULL, UNIQUE INDEX UNIQ_A7A91E0BE551C011 (hostname), INDEX IDX_A7A91E0B9033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enquiry (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, contact VARCHAR(180) NOT NULL, message LONGTEXT NOT NULL, locale VARCHAR(5) NOT NULL, handled TINYINT NOT NULL, created_at DATETIME NOT NULL, tenant_id INT NOT NULL, INDEX IDX_9D9969848B8E8428 (created_at), INDEX IDX_9D9969849033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, title JSON NOT NULL, description JSON NOT NULL, image_name VARCHAR(255) DEFAULT NULL, completed_at DATE DEFAULT NULL, published TINYINT NOT NULL, created_at DATETIME NOT NULL, customer_contact VARCHAR(180) DEFAULT NULL, review_token VARCHAR(64) DEFAULT NULL, review_requested_at DATETIME DEFAULT NULL, tenant_id INT NOT NULL, UNIQUE INDEX UNIQ_2FB3D0EE4CB98D20 (review_token), INDEX IDX_2FB3D0EE9033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, author_name VARCHAR(80) NOT NULL, town VARCHAR(80) DEFAULT NULL, rating SMALLINT NOT NULL, text LONGTEXT NOT NULL, locale VARCHAR(5) NOT NULL, published TINYINT NOT NULL, created_at DATETIME NOT NULL, project_id INT DEFAULT NULL, tenant_id INT NOT NULL, INDEX IDX_794381C6683C60178B8E8428 (published, created_at), INDEX IDX_794381C6166D1F9C (project_id), INDEX IDX_794381C69033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service (id INT AUTO_INCREMENT NOT NULL, title JSON NOT NULL, description JSON NOT NULL, position INT NOT NULL, tenant_id INT NOT NULL, INDEX IDX_E19D9AD29033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE signup (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, business_name VARCHAR(120) NOT NULL, contact_name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(30) NOT NULL, whatsapp VARCHAR(30) DEFAULT NULL, street_address VARCHAR(255) NOT NULL, postal_code VARCHAR(20) NOT NULL, city VARCHAR(120) NOT NULL, country VARCHAR(2) NOT NULL, service_area VARCHAR(255) DEFAULT NULL, tax_id VARCHAR(20) DEFAULT NULL, domain VARCHAR(253) DEFAULT NULL, locales JSON NOT NULL, default_locale VARCHAR(5) NOT NULL, billing_plan VARCHAR(10) DEFAULT \'monthly\' NOT NULL, message LONGTEXT DEFAULT NULL, password_hash VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, tenant_id INT DEFAULT NULL, INDEX IDX_4EA31EEF7B00651C (status), INDEX IDX_4EA31EEF9033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tenant (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(80) NOT NULL, active TINYINT NOT NULL, locales JSON NOT NULL, default_locale VARCHAR(5) NOT NULL, primary_color VARCHAR(7) NOT NULL, phone VARCHAR(30) DEFAULT NULL, whatsapp VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, service_area VARCHAR(255) DEFAULT NULL, street_address VARCHAR(255) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, country VARCHAR(2) NOT NULL, show_street_address TINYINT NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, logo_name VARCHAR(255) DEFAULT NULL, tagline JSON NOT NULL, intro JSON NOT NULL, about JSON NOT NULL, trades JSON NOT NULL, review_count INT NOT NULL, review_average DOUBLE PRECISION DEFAULT NULL, legal_name VARCHAR(180) DEFAULT NULL, tax_id VARCHAR(20) DEFAULT NULL, legal_address VARCHAR(255) DEFAULT NULL, google_site_verification VARCHAR(100) DEFAULT NULL, plausible_domain VARCHAR(255) DEFAULT NULL, search_console_token VARCHAR(100) DEFAULT NULL, search_console_status VARCHAR(20) NOT NULL, search_console_message VARCHAR(500) DEFAULT NULL, google_checked_at DATETIME DEFAULT NULL, google_business_url VARCHAR(500) DEFAULT NULL, billing_email VARCHAR(180) DEFAULT NULL, billing_exempt TINYINT NOT NULL, monthly_price INT DEFAULT NULL, billing_plan VARCHAR(10) DEFAULT \'monthly\' NOT NULL, renewal_notice_sent_for DATE DEFAULT NULL, billing_starts_on DATE DEFAULT NULL, mollie_customer_id VARCHAR(40) DEFAULT NULL, mollie_mandate_id VARCHAR(40) DEFAULT NULL, mandate_description VARCHAR(120) DEFAULT NULL, last_checkout_payment_id VARCHAR(40) DEFAULT NULL, billing_token VARCHAR(64) DEFAULT NULL, suspended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_4E59C462989D9B62 (slug), UNIQUE INDEX UNIQ_4E59C4625A488416 (billing_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE turnstile_widget (id INT AUTO_INCREMENT NOT NULL, site_key VARCHAR(100) NOT NULL, secret_key VARCHAR(255) NOT NULL, hostnames JSON NOT NULL, UNIQUE INDEX UNIQ_DE04D6677E0D9A89 (site_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E99033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE charge ADD CONSTRAINT FK_556BA4349033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domain ADD CONSTRAINT FK_A7A91E0B9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enquiry ADD CONSTRAINT FK_9D9969849033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C69033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service ADD CONSTRAINT FK_E19D9AD29033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup ADD CONSTRAINT FK_4EA31EEF9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E99033212A');
        $this->addSql('ALTER TABLE charge DROP FOREIGN KEY FK_556BA4349033212A');
        $this->addSql('ALTER TABLE domain DROP FOREIGN KEY FK_A7A91E0B9033212A');
        $this->addSql('ALTER TABLE enquiry DROP FOREIGN KEY FK_9D9969849033212A');
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EE9033212A');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6166D1F9C');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C69033212A');
        $this->addSql('ALTER TABLE service DROP FOREIGN KEY FK_E19D9AD29033212A');
        $this->addSql('ALTER TABLE signup DROP FOREIGN KEY FK_4EA31EEF9033212A');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE charge');
        $this->addSql('DROP TABLE domain');
        $this->addSql('DROP TABLE enquiry');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE signup');
        $this->addSql('DROP TABLE tenant');
        $this->addSql('DROP TABLE turnstile_widget');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
