<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use Doctrine\DBAL\Exception\DriverException;
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

        self::assertSame(ImportRunStatus::Completed, $run->getStatus(), (string) $run->getReason());
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

    public function testUnreadableSubmissionIsReportedWithoutBlockingRemovals(): void
    {
        $submissions = Submissions::dataset('submissions-v1.json');
        $this->formApi()->serve($submissions);
        $this->runImport();
        // In the source from now on: the 1st submission lost its session (unreadable, but still there), the
        // 11th was deleted, and one has no id at all.
        $unreadable = $submissions[0]['id'];
        $submissions[0]['answers']['session'] = null;
        unset($submissions[10]);
        $submissions[] = Submissions::submission(submission: ['id' => null]);
        $this->formApi()->serve(array_values($submissions));
        $this->runImport();

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        // Only the deleted one is removed: the unreadable one is still in the source.
        self::assertSame([1, 2], [$run->getRemoved(), $run->getUnreadable()]);
        self::assertSame([
            ['submission' => $unreadable, 'reason' => 'no readable session'],
            ['submission' => null, 'reason' => 'no id'],
        ], $run->getProblems());
    }

    public function testSubmissionThatIsNotAnObjectIsReportedAsUnreadable(): void
    {
        $this->formApi()->script(1, new MockResponse(json_encode([
            'form' => ['id' => 'frm_7Hq2xL', 'title' => 'Découvrir les métiers en réalité virtuelle — Ain, novembre 2026'],
            'data' => [Submissions::submission(), null],
            'meta' => ['page' => 1, 'per_page' => 25, 'total' => 2, 'total_pages' => 1],
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]));

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame([['submission' => null, 'reason' => 'not an object']], $run->getProblems());
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
        self::assertSame([0, 0], [$run->getRemoved(), $this->removedEverywhere()]);
    }

    public function testTotalChangingDuringTheRunEndsItIncompleteWithoutRemovingAnything(): void
    {
        $this->sourceMissingOneSubmissionSinceTheLastCompleteRun();
        // After page 2 is read, a submission of page 1 is deleted: the next pages announce 291, and page 3 now
        // starts one submission later, so one is never read.
        $this->formApi()->changeAfterPage(2, static fn (array $submissions): array => \array_slice($submissions, 1));

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Incomplete, $run->getStatus());
        self::assertSame('Saw 291 submissions, the source announced a changing total.', $run->getReason());
        // The missing submission was missed by two runs in a row, but this one did not see the whole source.
        self::assertSame([0, 0], [$run->getRemoved(), $this->removedEverywhere()]);
    }

    public function testSubmissionReadTwiceAndOneNeverReadAtTheSameTotalEndsTheRunIncomplete(): void
    {
        $this->sourceMissingOneSubmissionSinceTheLastCompleteRun();
        // After page 2 is read, one submission arrives first and the last one is deleted: the total stays at 292,
        // but page 3 now starts one submission earlier, so the last one of page 2 is read again and the newcomer
        // never is.
        $this->formApi()->changeAfterPage(2, static fn (array $submissions): array => [
            Submissions::submission(submission: ['id' => 'sub_newcomer', 'created_at' => '2026-10-20T10:00:00Z']),
            ...\array_slice($submissions, 0, -1),
        ]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Incomplete, $run->getStatus());
        self::assertSame('Saw 291 submissions, the source announced 292.', $run->getReason());
        // The run reports what it judged: the submission read twice is seen once (ADR 0020).
        self::assertSame(291, $run->getSeen());
        self::assertSame([0, 0], [$run->getRemoved(), $this->removedEverywhere()]);
    }

    public function testUnreadableSubmissionsAreSeen(): void
    {
        $this->formApi()->serve([
            Submissions::submission(),
            Submissions::submission(['session' => null], ['id' => 'sub_nosession']),
            Submissions::submission(submission: ['id' => null]),
        ]);

        $run = $this->runImport();

        // Unreadable, but in the source: they count towards the total announced (ADR 0020).
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertSame([3, 2], [$run->getSeen(), $run->getUnreadable()]);
    }

    public function testUnreadableSubmissionReadTwiceIsSeenOnce(): void
    {
        // 30 submissions; the last of page 1 has no session, and no registration matches it.
        $submissions = [];
        for ($i = 0; $i < 30; ++$i) {
            $submissions[] = Submissions::submission(submission: ['id' => \sprintf('sub_%02d', $i)]);
        }
        $submissions[24]['answers']['session'] = null;
        $this->formApi()->serve($submissions);
        // After page 1 is read, one submission arrives first and the last one is deleted: the total stays at 30,
        // but page 2 now starts one submission earlier, so the unreadable one is read again and the newcomer never is.
        $this->formApi()->changeAfterPage(1, static fn (array $submissions): array => [
            Submissions::submission(submission: ['id' => 'sub_newcomer']),
            ...\array_slice($submissions, 0, -1),
        ]);

        $run = $this->runImport();

        self::assertSame(ImportRunStatus::Incomplete, $run->getStatus());
        self::assertSame(29, $run->getSeen());
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

    public function testDatabaseErrorOnAPageFailsTheRunWithoutRemovingAnything(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v2.json');
        $this->runImport();
        // Page 2 is served, then the database refuses to write it.
        $this->formApi()->changeAfterPage(2, function (array $submissions): array {
            $this->refuseWrites('registration');

            return $submissions;
        });

        $error = $this->runFailingImport();

        self::assertInstanceOf(DriverException::class, $error);
        [, , $run] = $this->storedRuns();
        self::assertSame(ImportRunStatus::Failed, $run->getStatus());
        self::assertStringStartsWith(DriverException::class.': ', (string) $run->getReason());
        self::assertStringContainsString('write refused by the test', (string) $run->getReason());
        // Page 1 was committed before the failure, page 2 was rolled back.
        self::assertSame(25, $run->getSeen());
        self::assertSame(ImportRunStatus::Failed, $this->roster('ses_01')->summary->lastRunStatus);
        // sub_5m3rfhnkfb left the source in v2 and this run would have been its second complete miss.
        self::assertSame(0, $this->roster('ses_01')->summary->removed);

        $this->acceptWrites();
        self::assertSame(2, $this->runImport()->getRemoved());
    }

    public function testDatabaseErrorWhileCompletingTheRunRemovesNothing(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        $this->formApi()->serveDataset('submissions-v2.json');
        $this->runImport();
        // Every page is written, then the run cannot be marked completed.
        $this->refuseWrites('import_run', "NEW.status = 'completed'");

        $this->runFailingImport();

        // The removals were rolled back with the completed status: a run that is not completed removes nothing.
        self::assertSame(0, $this->roster('ses_01')->summary->removed);
        self::assertSame(ImportRunStatus::Failed, $this->storedRuns()[2]->getStatus());
    }

    public function testRunThatCouldNotRecordItsFailureIsFailedByTheNextRun(): void
    {
        $this->formApi()->serveDataset('submissions-v1.json');
        $this->runImport();
        // The run cannot write anything from page 2 on, its failure included.
        $this->formApi()->changeAfterPage(2, function (array $submissions): array {
            $this->refuseWrites('import_run');

            return $submissions;
        });
        $this->runFailingImport();
        self::assertSame(ImportRunStatus::Running, $this->storedRuns()[1]->getStatus());
        $this->acceptWrites();

        $this->runImport();

        self::assertSame(
            [ImportRunStatus::Completed, ImportRunStatus::Failed, ImportRunStatus::Completed],
            array_map(static fn (ImportRun $run): ImportRunStatus => $run->getStatus(), $this->storedRuns()),
        );
        self::assertStringStartsWith('Interrupted', (string) $this->storedRuns()[1]->getReason());
    }

    /**
     * The v1 source, then the same source without its 11th submission: the last complete run missed it once.
     */
    private function sourceMissingOneSubmissionSinceTheLastCompleteRun(): void
    {
        $submissions = Submissions::dataset('submissions-v1.json');
        $this->formApi()->serve($submissions);
        $this->runImport();
        unset($submissions[10]);
        $this->formApi()->serve(array_values($submissions));
        self::assertSame(ImportRunStatus::Completed, $this->runImport()->getStatus());
    }

    private function removedEverywhere(): int
    {
        $removed = 0;
        foreach (['ses_01', 'ses_02', 'ses_03', 'ses_04'] as $session) {
            $removed += $this->roster($session)->summary->removed;
        }

        return $removed;
    }
}
