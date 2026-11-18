<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a client use its own templates (off by default)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD custom_templates TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP custom_templates');
    }
}
