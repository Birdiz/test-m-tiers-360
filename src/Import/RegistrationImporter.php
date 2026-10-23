<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportRun;
use App\Entity\Operation;
use App\Entity\Registration;
use App\Entity\Session;
use App\Import\Source\FormApi;
use App\Import\Source\SourcePageDto;
use App\Import\Source\SourceUnreadable;
use App\Import\Submission\SessionSnapshotDto;
use App\Import\Submission\SubmissionNormalizer;
use App\Import\Submission\UnreadableSubmission;
use App\Repository\ImportRunRepository;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One import run: reads every page of the source and applies each page in its own transaction (ADR 0009); the
 * run tally says what the run saw and how it ends (ADR 0020).
 *
 * Never throws on a source failure: the returned run says how it ended. Any other failure (database, bug) is
 * recorded on the run, marked failed, and rethrown (ADR 0016).
 */
final readonly class RegistrationImporter
{
    public function __construct(
        private FormApi $formApi,
        private SubmissionNormalizer $normalizer,
        private EntityManagerInterface $entityManager,
        private RegistrationRepository $registrations,
        private ImportRunRepository $runs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ValidatorInterface $validator,
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
        $tally = new RunTally($run, $this->registrations, $this->runs, $this->clock, $this->logger);
        try {
            foreach ($this->formApi->pages() as $page) {
                // Checked before the page's transaction: an exception inside it closes the entity manager.
                $this->assertStorableForm($page);
                $this->entityManager->wrapInTransaction(function () use ($page, $run, $tally): void {
                    $this->applyPage($page, $run, $tally);
                });
            }
        } catch (ExceptionInterface|SourceUnreadable $e) {
            $tally->fail($e->getMessage());
            $this->entityManager->flush();

            return;
        }

        // Removals and the completed status are written together: only a completed run removes anything (ADR 0002).
        $this->entityManager->wrapInTransaction(static function () use ($tally): void {
            $tally->finish();
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

    private function applyPage(SourcePageDto $page, ImportRun $run, RunTally $tally): void
    {
        $operation = $this->upsertOperation($page);

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

        $readable = array_map('strval', array_keys($submissions));
        $known = $this->registrations->findByExternalIds($operation, $readable);
        foreach ($submissions as $externalId => $submission) {
            $session = $this->upsertSession($operation, $submission->session);
            $registration = $known[$externalId] ?? null;
            if (null === $registration) {
                $this->entityManager->persist(new Registration($operation, $externalId, $submission, $session));
                $run->countCreated();
            } elseif ($registration->applyVersion($submission, $session)) {
                $run->countUpdated();
            }
        }

        $this->entityManager->flush();
        // After the flush: the registrations this page created are marked seen too.
        $tally->recordPage($operation, $page->total, $readable, $unreadable);
        // Keep memory to one page: registrations are not needed once written.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [] as $registration) {
            $this->entityManager->detach($registration);
        }
    }

    /**
     * Without a form id the database can hold, no registration can be tied to its operation (ADR 0014).
     *
     * @throws SourceUnreadable
     */
    private function assertStorableForm(SourcePageDto $page): void
    {
        if (str_contains($page->formId, "\0") || 0 !== \count($this->validator->validatePropertyValue(Operation::class, 'externalFormId', $page->formId))) {
            throw new SourceUnreadable(\sprintf('The source serves a form id we cannot store: "%s".', $page->formId));
        }
    }

    private function upsertOperation(SourcePageDto $page): Operation
    {
        // Describes no one, and an operation has no anomaly to keep the rest: cut to the column (ADR 0014).
        $title = mb_substr(str_replace("\0", '', $page->formTitle), 0, Operation::TITLE_LENGTH);
        $operation = $this->entityManager->getRepository(Operation::class)->findOneBy(['externalFormId' => $page->formId]);
        if (null === $operation) {
            $operation = new Operation($page->formId, $title);
            $this->entityManager->persist($operation);
            $this->entityManager->flush();
        }
        $operation->rename($title);

        return $operation;
    }

    private function upsertSession(Operation $operation, SessionSnapshotDto $snapshot): Session
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
