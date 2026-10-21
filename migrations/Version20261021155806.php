<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021155806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record unreadable submissions of each import run, and why a run failed or ended incomplete';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // The default only fills existing runs.
        $this->addSql('ALTER TABLE import_run ADD problems JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE import_run ALTER problems DROP DEFAULT');
        $this->addSql('ALTER TABLE import_run DROP unreadable');
        $this->addSql('ALTER TABLE import_run RENAME COLUMN error TO reason');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE import_run ADD unreadable INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE import_run ALTER unreadable DROP DEFAULT');
        $this->addSql('ALTER TABLE import_run DROP problems');
        $this->addSql('ALTER TABLE import_run RENAME COLUMN reason TO error');
    }
}
