<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\ImportRun;
use App\Import\RegistrationImporter;
use App\Roster\Roster;
use App\Roster\SessionRoster;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seam S1: the import is observed through what the ops team reads, the session roster.
 */
abstract class ImportTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)
            ->executeStatement('TRUNCATE registration, session, operation, import_run RESTART IDENTITY CASCADE');
    }

    protected function formApi(): FakeFormApi
    {
        return self::getContainer()->get(FakeFormApi::class);
    }

    protected function runImport(): ImportRun
    {
        return self::getContainer()->get(RegistrationImporter::class)->run();
    }

    protected function roster(string $sessionExternalId): Roster
    {
        $roster = self::getContainer()->get(SessionRoster::class)->forSession($sessionExternalId);
        self::assertNotNull($roster, "No roster for $sessionExternalId");

        return $roster;
    }
}
