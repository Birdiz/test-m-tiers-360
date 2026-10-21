<?php

namespace App\Tests\Import;

use App\Entity\ImportRunStatus;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        self::assertSame([12, 11, 0], [$run->getCreated(), $run->getUpdated(), $run->getRemoved()]);
        // A registration is removed once missing from two complete runs in a row (ADR 0002).
        self::assertSame(2, $this->runImport()->getRemoved());
        $summaries = array_map(fn (string $id): array => [
            $this->roster($id)->summary->active,
            $this->roster($id)->summary->cancelled,
            $this->roster($id)->summary->removed,
        ], ['ses_01', 'ses_02', 'ses_03', 'ses_04']);
        self::assertSame([[78, 5, 1], [53, 1, 1], [49, 2, 0], [109, 6, 0]], $summaries);
    }

    public function testRemovedRegistrationThatComesBackIsActiveAgain(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v2.json');
        $this->runImport();
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v1.json');

        $this->runImport();

        // sub_5m3rfhnkfb (ses_01), removed after two v2 runs, is back in the source.
        self::assertSame(0, $this->roster('ses_01')->summary->removed);
    }

    public function testServerErrorAndSlowPageAreRetried(): void
    {
        // What the kit's mock does: page 5 fails once, page 8 is too slow every other call.
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->formApi()->script(5, FakeFormApi::serverError());
        $this->formApi()->script(8, FakeFormApi::maxDurationReached());

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getError());
        self::assertSame(293, $run->getSeen());
        self::assertSame([1, 2, 3, 4, 5, 5, 6, 7, 8, 8, 9, 10, 11, 12], $this->formApi()->requestedPages());
    }

    public function testSourceStillFailingAfterRetriesFailsTheRunWithoutRemovingAnything(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v2.json');
        $this->formApi()->script(5, ...array_fill(0, 4, FakeFormApi::serverError()));

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertSame(0, $run->getRemoved());
        // Once in the first run, then 1 call + 3 retries in this one.
        self::assertCount(5, array_keys($this->formApi()->requestedPages(), 5));
        // sub_5m3rfhnkfb left the source in v2, but this run did not see the whole source.
        self::assertSame(0, $this->roster('ses_01')->summary->removed);
        // Pages read before the failure are applied: page 1 of v2 holds cancellations of ses_01.
        self::assertSame(100, $run->getSeen());
    }

    public function testClientErrorIsNotRetried(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->formApi()->script(1, new MockResponse('{"error":"invalid_parameter"}', ['http_code' => 400]));

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertSame([1], $this->formApi()->requestedPages());
    }

    public function testUnreadableSubmissionMakesTheRunIncompleteSoNothingIsRemoved(): void
    {
        $submissions = Submissions::dataset('submissions-v1.json');
        $this->formApi()->serve($submissions);
        $this->runImport();
        $submissions[0]['answers']['session'] = null;
        $this->formApi()->serve(\array_slice($submissions, 0, 50));

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Incomplete, $run->getStatus());
        self::assertSame([49, 1, 0], [$run->getSeen(), $run->getUnreadable(), $run->getRemoved()]);
    }

    public function testRegistrationSkippedByAPageShiftIsNotRemoved(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->runImport();
        // After page 2 is read, one submission of page 1 is deleted and a new one arrives: the total stays at
        // 293, but page 3 now starts one submission later, so one is never read.
        $this->formApi()->changeAfterPage(2, static fn (array $submissions): array => [
            ...\array_slice($submissions, 1),
            Submissions::submission(submission: ['id' => 'sub_newcomer', 'created_at' => '2026-10-20T10:00:00Z']),
        ]);

        $run = $this->runImport();

        // Seen 293 = total announced, yet one registration still in the source was skipped: the count alone
        // cannot tell. It is not removed, because it was seen in the previous complete run.
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame(0, $run->getRemoved());
        self::assertSame(0, $this->roster('ses_01')->summary->removed + $this->roster('ses_02')->summary->removed
            + $this->roster('ses_03')->summary->removed + $this->roster('ses_04')->summary->removed);
    }

    public function testOlderVersionThanTheOneKnownIsIgnored(): void
    {
        $submission = Submissions::submission(submission: ['status' => 'cancelled', 'updated_at' => '2026-10-02T09:00:00Z']);
        $this->formApi()->serve([$submission]);
        $this->runImport();
        // A stale copy (cache, replica): still submitted, last modified before the cancellation.
        $this->formApi()->serve([Submissions::submission(submission: ['updated_at' => '2026-09-20T09:00:00Z'])]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame([0, 1], [$this->roster('ses_01')->summary->active, $this->roster('ses_01')->summary->cancelled]);
    }
}
