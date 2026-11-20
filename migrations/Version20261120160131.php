<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120160131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project pages: more photos, location, service and duration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD photos JSON DEFAULT \'[]\' NOT NULL, ADD location VARCHAR(120) DEFAULT NULL, ADD duration_days SMALLINT DEFAULT NULL, ADD service_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EEED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2FB3D0EEED5CA9E6 ON project (service_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_2FB3D0EEED5CA9E6');
        $this->addSql('DROP INDEX IDX_2FB3D0EEED5CA9E6 ON project');
        $this->addSql('ALTER TABLE project DROP photos, DROP location, DROP duration_days, DROP service_id');
    }
}
