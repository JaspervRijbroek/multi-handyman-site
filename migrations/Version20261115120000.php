<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store when a client accepted the terms and privacy policy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup ADD terms_accepted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD terms_accepted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup DROP terms_accepted_at');
        $this->addSql('ALTER TABLE tenant DROP terms_accepted_at');
    }
}
