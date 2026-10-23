<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Enum\ImportRunStatus;
use App\Import\Submission\Anomaly;
use App\Import\Submission\AnomalyCode;
use App\Roster\RosterEntry;

/**
 * Source values our columns cannot hold: each used to abort the run on a database error (ADR 0014).
 */
final class ValuesBeyondTheSchemaTest extends ImportTestCase
{
    public function testOverlongAnswersAreImportedEmptyAndFlagged(): void
    {
        $longName = str_repeat('é', 256);
        $this->formApi()->serve([Submissions::submission(['last_name' => $longName, 'structure_name' => $longName])]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getReason());
        $entry = $this->roster('ses_01')->registrations[0];
        self::assertSame(['', null], [$entry->lastName, $entry->structureName]);
        self::assertEquals([
            new Anomaly('last_name', AnomalyCode::TooLong, $longName),
            new Anomaly('structure_name', AnomalyCode::TooLong, $longName),
        ], $entry->anomalies);
    }

    public function testSubmissionThatCannotBeStoredIsReportedAndTheOthersImported(): void
    {
        $longId = 'sub_'.str_repeat('x', 61);
        $this->formApi()->serve([
            Submissions::submission(submission: ['id' => $longId]),
            Submissions::submission(['session' => ['id' => 'ses_'.str_repeat('x', 61), 'date' => '2026-11-17', 'capacity' => 80]], ['id' => 'sub_long_session']),
            Submissions::submission(['session' => ['id' => 'ses_01', 'date' => '2026-11-17', 'capacity' => 3_000_000_000]], ['id' => 'sub_capacity']),
            Submissions::submission(['session' => ['id' => 'ses_01', 'date' => '0000-11-17', 'capacity' => 80]], ['id' => 'sub_year_zero']),
            Submissions::submission(submission: ['id' => 'sub_created_year_zero', 'created_at' => '0000-09-14T07:29:00Z']),
            Submissions::submission(submission: ['id' => 'sub_ok']),
        ]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getReason());
        self::assertSame([
            ['submission' => $longId, 'reason' => 'id our database cannot hold'],
            ['submission' => 'sub_long_session', 'reason' => 'no readable session'],
            ['submission' => 'sub_capacity', 'reason' => 'no readable session'],
            ['submission' => 'sub_year_zero', 'reason' => 'no readable session'],
            ['submission' => 'sub_created_year_zero', 'reason' => 'unreadable created_at'],
        ], $run->getProblems());
        self::assertSame(['sub_ok'], array_map(static fn (RosterEntry $entry): string => $entry->externalId, $this->roster('ses_01')->registrations));
    }

    public function testNulByteIsDroppedSoTheSameSubmissionIsFoundAgain(): void
    {
        // Postgres cannot store a NUL byte: the driver cuts the value there, and the id no longer matches.
        $this->formApi()->serve([Submissions::submission(['comment' => "PMR\0"], ['id' => "sub_k3m9\0qz7tpe"])]);
        $this->runImport();

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getReason());
        self::assertSame([0, 0], [$run->getCreated(), $run->getUpdated()]);
        self::assertSame(['sub_k3m9qz7tpe', 'PMR'], [$this->roster('ses_01')->registrations[0]->externalId, $this->roster('ses_01')->registrations[0]->comment]);
    }

    public function testFormThatCannotBeStoredFailsTheRunAndAnOverlongTitleIsCut(): void
    {
        $this->formApi()->serve([Submissions::submission()]);
        $this->formApi()->form('frm_'.str_repeat('x', 61), 'Ain');

        $failed = $this->runImport();

        // Like a source that cannot be read: nothing applied, nothing removed (ADR 0002).
        self::assertSame([ImportRunStatus::Failed, 0], [$failed->getStatus(), $failed->getSeen()]);

        $this->formApi()->form('frm_7Hq2xL', str_repeat('T', 300)."\0");

        self::assertSame(ImportRunStatus::Completed, $this->runImport()->getStatus());
        self::assertSame(str_repeat('T', 255), $this->roster('ses_01')->session->getOperation()->getTitle());
    }

    public function testOverlongSessionDetailsAreCut(): void
    {
        $this->formApi()->serve([Submissions::submission(['session' => [
            'id' => 'ses_01',
            'label' => str_repeat('L', 300),
            'date' => '2026-11-17',
            'location' => str_repeat('é', 300),
            'capacity' => 80,
        ]])]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getReason());
        $session = $this->roster('ses_01')->session;
        self::assertSame([str_repeat('L', 255), str_repeat('é', 255)], [$session->getLabel(), $session->getLocation()]);
    }
}
