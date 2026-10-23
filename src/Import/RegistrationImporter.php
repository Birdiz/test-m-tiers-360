<?php

namespace App\Import;

use App\Entity\ImportRun;
use App\Entity\Operation;
use App\Entity\Registration;
use App\Entity\Session;
use App\Repository\ImportRunRepository;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One import run: reads every page of the source, applies each page in its own transaction, and removes
 * what two complete runs in a row did not find in the source (ADRs 0002, 0009).
 *
 * Never throws on a source failure: the returned run says how it ended. Any other failure (database, bug) is
 * recorded on the run, marked failed, and rethrown (ADR 0013).
 */
final class RegistrationImporter
{
    public function __construct(
        private readonly FormApi $formApi,
        private readonly SubmissionNormalizer $normalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly RegistrationRepository $registrations,
        private readonly ImportRunRepository $runs,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Expects to be the only run in progress: the import command holds a lock (ADR 0009).
     *
     * @throws \Throwable when something other than the source stopped the run
     */
    public function run(): ImportRun
    {
        // A run still running now was killed, or lost the database before it could record its failure.
        $this->runs->failRunning('Interrupted before it finished: the process stopped or lost the database.', $this->clock->now());
        $run = new ImportRun($this->clock->now());
        $this->entityManager->persist($run);
        // Nowhere to record a failure of this insert: the exception is the only report.
        $this->entityManager->flush();

        try {
            $this->import($run);
        } catch (\Throwable $e) {
            $this->recordFailure($run, $e);

            throw $e;
        }

        return $run;
    }

    private function import(ImportRun $run): void
    {
        $operation = null;
        $total = null;
        $totalChanged = false;
        // Unreadable submissions that match no known registration: in the source, but not countable in our table.
        $unmatched = 0;
        try {
            foreach ($this->formApi->pages() as $page) {
                $totalChanged = $totalChanged || (null !== $total && $total !== $page->total);
                $total ??= $page->total;
                $operation = $this->entityManager->wrapInTransaction(function () use ($page, $run, &$unmatched): Operation {
                    return $this->applyPage($page, $run, $unmatched);
                });
            }
        } catch (ExceptionInterface|SourceUnreadable $e) {
            $this->logger->error('Import run failed: {error}', ['error' => $e->getMessage()]);
            $run->fail($e->getMessage(), $this->clock->now());
            $this->entityManager->flush();

            return;
        }

        $seen = null === $operation ? 0 : $this->registrations->countSeenIn($operation, $run) + $unmatched;
        if (null === $operation || $totalChanged || $seen !== $total) {
            $this->logger->warning('Import run incomplete: saw {seen} of {total} submissions, nothing removed.', ['seen' => $seen, 'total' => $total]);
            $run->endIncomplete(\sprintf('Saw %d submissions, the source announced %s.', $seen, $totalChanged ? 'a changing total' : $total), $this->clock->now());
            $this->entityManager->flush();

            return;
        }

        // Removals and the completed status are written together: only a completed run removes anything (ADR 0002).
        $this->entityManager->wrapInTransaction(function () use ($operation, $run): void {
            // The count cannot catch a deletion and an insertion that cancel out while pages shift: a registration
            // skipped that way is only removed if the previous complete run missed it too (ADR 0002).
            $previous = $this->runs->lastCompletedBefore($run);
            $removed = null === $previous ? 0 : $this->registrations->removeNotSeenSince($operation, $previous, $this->clock->now());
            $run->complete($removed, $this->clock->now());
        });
    }

    /**
     * Written with a query: after a database error the entity manager is closed and cannot flush the run. Only the
     * message's first line is kept: Postgres details can quote the rejected row, personal data included.
     */
    private function recordFailure(ImportRun $run, \Throwable $e): void
    {
        $run->fail(\sprintf('%s: %s', $e::class, explode("\n", $e->getMessage(), 2)[0]), $this->clock->now());
        try {
            $this->runs->recordFailure($run);
        } catch (\Throwable $recording) {
            // The database is likely gone: the next run will mark this one failed.
            $this->logger->error('Could not mark import run {run} failed: {error}', ['run' => $run->getId(), 'error' => $recording->getMessage()]);
        }
    }

    private function applyPage(SourcePage $page, ImportRun $run, int &$unmatched): Operation
    {
        $operation = $this->operation($page);
        $runId = (int) $run->getId();

        $submissions = [];
        $unreadable = [];
        foreach ($page->submissions as $raw) {
            try {
                $submission = $this->normalizer->normalize($raw);
                $submissions[$submission->externalId] = $submission;
            } catch (UnreadableSubmission $e) {
                $this->logger->warning('Unreadable submission {submission} skipped: {reason}', ['submission' => $e->externalId, 'reason' => $e->getMessage()]);
                $run->reportUnreadable($e->externalId, $e->getMessage());
                $unreadable[] = $e->externalId;
            }
        }

        $ids = [...array_keys($submissions), ...array_filter($unreadable)];
        $known = $this->registrations->findByExternalIds($operation, array_map('strval', $ids));
        // An unreadable submission we already know is still in the source: it must not be removed.
        foreach ($unreadable as $externalId) {
            null !== $externalId && isset($known[$externalId]) ? $known[$externalId]->stillInSource($runId) : ++$unmatched;
        }

        foreach ($submissions as $externalId => $submission) {
            $session = $this->session($operation, $submission->session);
            $registration = $known[$externalId] ?? null;
            if (null === $registration) {
                $this->entityManager->persist(new Registration($operation, $externalId, $submission, $session, $runId));
                $run->countCreated();
            } elseif ($registration->seenInSource($submission, $session, $runId)) {
                $run->countUpdated();
            }
            $run->countSeen();
        }

        $this->entityManager->flush();
        // Keep memory to one page: registrations are not needed once written.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [] as $registration) {
            $this->entityManager->detach($registration);
        }

        return $operation;
    }

    private function operation(SourcePage $page): Operation
    {
        $operation = $this->entityManager->getRepository(Operation::class)->findOneBy(['externalFormId' => $page->formId]);
        if (null === $operation) {
            $operation = new Operation($page->formId, $page->formTitle);
            $this->entityManager->persist($operation);
            $this->entityManager->flush();
        }
        $operation->rename($page->formTitle);

        return $operation;
    }

    private function session(Operation $operation, SessionSnapshot $snapshot): Session
    {
        $session = $this->entityManager->getRepository(Session::class)->findOneBy(['operation' => $operation, 'externalId' => $snapshot->externalId]);
        if (null === $session) {
            $session = new Session($operation, $snapshot->externalId, $snapshot);
            $this->entityManager->persist($session);
            // Flushed now so the next submission of the same page finds it.
            $this->entityManager->flush();
        }
        $session->describe($snapshot);

        return $session;
    }
}
