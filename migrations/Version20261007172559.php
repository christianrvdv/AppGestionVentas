<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007172559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_movement_idempotency');
        $this->addSql('CREATE UNIQUE INDEX uniq_movement_tenant_idempotency ON inventory_movement (tenant_id, idempotency_key)');
        $this->addSql('ALTER TABLE investment ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE investment_item ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE investment_item ADD fixed_price NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE investment_summary ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE sale ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_movement_tenant_idempotency');
        $this->addSql('CREATE UNIQUE INDEX uniq_movement_idempotency ON inventory_movement (idempotency_key)');
        $this->addSql('ALTER TABLE investment DROP version');
        $this->addSql('ALTER TABLE investment_item DROP version');
        $this->addSql('ALTER TABLE investment_item DROP fixed_price');
        $this->addSql('ALTER TABLE investment_summary DROP version');
        $this->addSql('ALTER TABLE sale DROP version');
    }
}
