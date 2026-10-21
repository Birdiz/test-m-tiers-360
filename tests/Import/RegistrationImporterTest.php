<?php

namespace App\Tests\Import;

use App\Entity\ImportRunStatus;

final class RegistrationImporterTest extends ImportTestCase
{
    public function testImportingTheSourceMakesEachSessionReadable(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        // Expected counts computed from mock-api/data/submissions-v1.json outside the application.
        self::assertSame([80, 3], [$this->roster('ses_01')->summary->active, $this->roster('ses_01')->summary->cancelled]);
        self::assertSame([47, 1], [$this->roster('ses_03')->summary->active, $this->roster('ses_03')->summary->cancelled]);
    }
}
