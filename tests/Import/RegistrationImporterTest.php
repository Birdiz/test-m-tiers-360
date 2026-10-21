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

    public function testRunningTheSameSourceTwiceChangesNothing(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();

        $second = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $second->getStatus());
        self::assertSame([293, 0, 0, 0], [$second->getSeen(), $second->getCreated(), $second->getUpdated(), $second->getRemoved()]);
        self::assertSame(80, $this->roster('ses_01')->summary->active);
    }

    public function testNextRunAppliesWhatChangedInTheSource(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v2.json');

        $run = $this->runImport();

        // v1 → v2: 12 new, 11 edited (7 cancelled, 3 moved from ses_02 to ses_03, 1 renamed), 2 gone.
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame([12, 11, 2], [$run->getCreated(), $run->getUpdated(), $run->getRemoved()]);
        $summaries = array_map(fn (string $id): array => [
            $this->roster($id)->summary->active,
            $this->roster($id)->summary->cancelled,
            $this->roster($id)->summary->removed,
        ], ['ses_01', 'ses_02', 'ses_03', 'ses_04']);
        self::assertSame([[78, 5, 1], [53, 1, 1], [49, 2, 0], [109, 6, 0]], $summaries);
    }
}
