<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023180345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make registration.last_seen_run_id nullable, so the run tally marks a new registration as seen once the import has written it, see ADR 0020';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registration ALTER last_seen_run_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registration ALTER last_seen_run_id SET NOT NULL');
    }
}
