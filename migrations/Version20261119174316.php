<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119174316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations: clients invite other businesses; free months on the next payment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, tenant_id INT NOT NULL, UNIQUE INDEX UNIQ_F11D61A25F37A13B (token), INDEX IDX_F11D61A29033212A (tenant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A29033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE charge ADD free_months SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE signup ADD invitation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE signup ADD CONSTRAINT FK_4EA31EEFA35D7AF0 FOREIGN KEY (invitation_id) REFERENCES invitation (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4EA31EEFA35D7AF0 ON signup (invitation_id)');
        $this->addSql('ALTER TABLE tenant ADD free_months INT DEFAULT 0 NOT NULL, ADD inviter_rewarded_at DATETIME DEFAULT NULL, ADD invited_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT FK_4E59C462A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES tenant (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4E59C462A7B4A7E3 ON tenant (invited_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup DROP FOREIGN KEY FK_4EA31EEFA35D7AF0');
        $this->addSql('DROP INDEX UNIQ_4EA31EEFA35D7AF0 ON signup');
        $this->addSql('ALTER TABLE signup DROP invitation_id');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A29033212A');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('ALTER TABLE charge DROP free_months');
        $this->addSql('ALTER TABLE tenant DROP FOREIGN KEY FK_4E59C462A7B4A7E3');
        $this->addSql('DROP INDEX IDX_4E59C462A7B4A7E3 ON tenant');
        $this->addSql('ALTER TABLE tenant DROP free_months, DROP inviter_rewarded_at, DROP invited_by_id');
    }
}
