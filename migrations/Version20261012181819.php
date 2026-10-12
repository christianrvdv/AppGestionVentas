<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012181819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 6.1: app_user.must_change_password marca que la contraseña vigente es temporal (reset del panel admin) y debe cambiarse en el próximo login.';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT false: el ALTER corre sobre filas existentes, que no
        // deben heredar la condición de "debe cambiar contraseña".
        $this->addSql('ALTER TABLE app_user ADD must_change_password BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_user DROP must_change_password');
    }
}
