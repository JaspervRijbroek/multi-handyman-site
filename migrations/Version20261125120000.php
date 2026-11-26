<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email language per client and sign-up';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant ADD email_locale VARCHAR(5) DEFAULT 'en' NOT NULL");
        $this->addSql("ALTER TABLE signup ADD email_locale VARCHAR(5) DEFAULT 'en' NOT NULL");
        // Existing clients: their website's main language is the best guess
        $this->addSql('UPDATE tenant SET email_locale = default_locale');
        $this->addSql('UPDATE signup SET email_locale = default_locale');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP email_locale');
        $this->addSql('ALTER TABLE signup DROP email_locale');
    }
}
