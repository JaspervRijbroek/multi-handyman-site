<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261114142228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FacturaDirecta and MCP token columns';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE charge ADD factura_directa_invoice_id VARCHAR(64) DEFAULT NULL, ADD invoice_number VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD factura_directa_contact_id VARCHAR(64) DEFAULT NULL, ADD mcp_token_hash VARCHAR(64) DEFAULT NULL, ADD mcp_token_created_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4E59C462C69FA79F ON tenant (mcp_token_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE charge DROP factura_directa_invoice_id, DROP invoice_number');
        $this->addSql('DROP INDEX UNIQ_4E59C462C69FA79F ON tenant');
        $this->addSql('ALTER TABLE tenant DROP factura_directa_contact_id, DROP mcp_token_hash, DROP mcp_token_created_at');
    }
}
