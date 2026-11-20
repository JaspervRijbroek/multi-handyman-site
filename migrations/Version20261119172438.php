<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119172438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Founding members: first year free, second year 50% off (off by default)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup ADD founding_member TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE tenant ADD founding_member TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup DROP founding_member');
        $this->addSql('ALTER TABLE tenant DROP founding_member');
    }
}
