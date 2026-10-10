<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Associate seasons with their championship division';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saison ADD COLUMN division_id INTEGER DEFAULT NULL CONSTRAINT FK_C0D0D58641859289 REFERENCES division (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_C0D0D58641859289 ON saison (division_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_C0D0D58641859289');
        $this->addSql('ALTER TABLE saison DROP COLUMN division_id');
    }
}