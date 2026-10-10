<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve each team entry division for historical season standings';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('saison_engagement_division');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('engagement_id', Types::INTEGER);
        $table->addColumn('division_id', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['engagement_id'], 'UNIQ_CF7A5C4D30F6F97');
        $table->addIndex(['division_id'], 'IDX_CF7A5C441859289');
        $table->addForeignKeyConstraint('engagement', ['engagement_id'], ['id'], [], 'FK_CF7A5C4D30F6F97');
        $table->addForeignKeyConstraint('division', ['division_id'], ['id'], [], 'FK_CF7A5C441859289');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('saison_engagement_division');
    }
}