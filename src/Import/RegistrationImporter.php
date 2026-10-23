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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One import run: reads every page of the source, applies each page in its own transaction, and removes
 * what two complete runs in a row did not find in the source (ADRs 0002, 0009).
 *
 * Never throws on a source failure: the returned run says how it ended.
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
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function run(): ImportRun
    {
        $run = new ImportRun($this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $operation = null;
        $total = null;
        $totalChanged = false;
        // Unreadable submissions that match no known registration: in the source, but not countable in our table.
        $unmatched = 0;
        try {
            foreach ($this->formApi->pages() as $page) {
                // Checked before the page's transaction: an exception inside it closes the entity manager.
                $this->assertStorableForm($page);
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

            return $run;
        }

        $seen = null === $operation ? 0 : $this->registrations->countSeenIn($operation, $run) + $unmatched;
        if (null === $operation || $totalChanged || $seen !== $total) {
            $this->logger->warning('Import run incomplete: saw {seen} of {total} submissions, nothing removed.', ['seen' => $seen, 'total' => $total]);
            $run->endIncomplete(\sprintf('Saw %d submissions, the source announced %s.', $seen, $totalChanged ? 'a changing total' : $total), $this->clock->now());
        } else {
            // The count cannot catch a deletion and an insertion that cancel out while pages shift: a registration
            // skipped that way is only removed if the previous complete run missed it too (ADR 0002).
            $previous = $this->runs->lastCompletedBefore($run);
            $removed = null === $previous ? 0 : $this->registrations->removeNotSeenSince($operation, $previous, $this->clock->now());
            $run->complete($removed, $this->clock->now());
        }
        $this->entityManager->flush();

        return $run;
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

    /**
     * Without a form id the database can hold, no registration can be tied to its operation (ADR 0014).
     *
     * @throws SourceUnreadable
     */
    private function assertStorableForm(SourcePage $page): void
    {
        if (str_contains($page->formId, "\0") || 0 !== \count($this->validator->validatePropertyValue(Operation::class, 'externalFormId', $page->formId))) {
            throw new SourceUnreadable(\sprintf('The source serves a form id we cannot store: "%s".', $page->formId));
        }
    }

    private function operation(SourcePage $page): Operation
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
