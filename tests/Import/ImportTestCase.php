<?php

namespace App\Tests\Import;

use App\Entity\ImportRun;
use App\Import\RegistrationImporter;
use App\Repository\ImportRunRepository;
use App\Roster\Roster;
use App\Roster\SessionRoster;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seam S1: the import is observed through what the ops team reads, the session roster.
 */
abstract class ImportTestCase extends KernelTestCase
{
    /** @var list<string> tables the database refuses writes to */
    private array $refused = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)
            ->executeStatement('TRUNCATE registration, session, operation, import_run RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        $this->acceptWrites();
        parent::tearDown();
    }

    protected function formApi(): FakeFormApi
    {
        return self::getContainer()->get(FakeFormApi::class);
    }

    protected function runImport(): ImportRun
    {
        return self::getContainer()->get(RegistrationImporter::class)->run();
    }

    /**
     * Runs an import that throws, then starts afresh like the next cron run, a new process with a new entity manager.
     */
    protected function runFailingImport(): \Throwable
    {
        try {
            $this->runImport();
        } catch (\Throwable $e) {
            self::getContainer()->get(ManagerRegistry::class)->resetManager();

            return $e;
        }
        self::fail('The import run did not throw.');
    }

    /**
     * The database rejects the writes to this table that match the condition, as a full disk or a lost primary would.
     */
    protected function refuseWrites(string $table, string $condition = 'true'): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE OR REPLACE FUNCTION refuse_write() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN RAISE EXCEPTION 'write refused by the test'; END \$\$");
        $connection->executeStatement("CREATE TRIGGER refuse_write BEFORE INSERT OR UPDATE ON $table FOR EACH ROW WHEN ($condition) EXECUTE FUNCTION refuse_write()");
        $this->refused[] = $table;
    }

    protected function acceptWrites(): void
    {
        foreach ($this->refused as $table) {
            self::getContainer()->get(Connection::class)->executeStatement("DROP TRIGGER refuse_write ON $table");
        }
        $this->refused = [];
    }

    /**
     * @return list<ImportRun> oldest first, as stored
     */
    protected function storedRuns(): array
    {
        // Runs are also written by bulk queries, which loaded entities do not see.
        self::getContainer()->get(ManagerRegistry::class)->getManager()->clear();

        return self::getContainer()->get(ImportRunRepository::class)->findBy([], ['id' => 'ASC']);
    }

    protected function roster(string $sessionExternalId): Roster
    {
        $roster = self::getContainer()->get(SessionRoster::class)->forSession($sessionExternalId);
        self::assertNotNull($roster, "No roster for $sessionExternalId");

        return $roster;
    }
}
