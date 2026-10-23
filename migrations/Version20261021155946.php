<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021155946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add registration.joined_session_at, backfilled from source_created_at, so the queue for capacity runs from when a registration joined its session, see ADR 0004';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Existing registrations queue from their submission, as the import does for a new one.
        $this->addSql('ALTER TABLE registration ADD joined_session_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE registration SET joined_session_at = source_created_at');
        $this->addSql('ALTER TABLE registration ALTER joined_session_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registration DROP joined_session_at');
    }
}
