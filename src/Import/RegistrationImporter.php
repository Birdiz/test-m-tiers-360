<?php

namespace App\Import;

use App\Entity\ImportRun;
use App\Entity\Operation;
use App\Entity\Registration;
use App\Entity\Session;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * One import run: reads every page of the source, applies each page in its own transaction, and removes
 * what the source no longer has only when the run saw the whole source (ADRs 0002, 0009).
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
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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
        try {
            foreach ($this->formApi->pages() as $page) {
                $totalChanged = $totalChanged || (null !== $total && $total !== $page->total);
                $total ??= $page->total;
                $operation = $this->entityManager->wrapInTransaction(fn (): Operation => $this->applyPage($page, $run));
            }
        } catch (ExceptionInterface|SourceUnreadable $e) {
            $this->logger->error('Import run failed: {error}', ['error' => $e->getMessage()]);
            $run->fail($e->getMessage(), $this->clock->now());
            $this->entityManager->flush();

            return $run;
        }

        $seen = null === $operation ? 0 : $this->registrations->countSeenIn($operation, $run);
        if (null === $operation || $totalChanged || $seen !== $total) {
            $run->endIncomplete(\sprintf('Saw %d registrations, the source announced %s.', $seen, $totalChanged ? 'a changing total' : $total), $this->clock->now());
        } else {
            $removed = $this->registrations->removeNotSeenIn($operation, $run, $this->clock->now());
            $run->complete($removed, $this->clock->now());
        }
        $this->entityManager->flush();

        return $run;
    }

    private function applyPage(SourcePage $page, ImportRun $run): Operation
    {
        $operation = $this->operation($page);
        $runId = (int) $run->getId();

        $submissions = [];
        foreach ($page->submissions as $raw) {
            try {
                $submission = $this->normalizer->normalize($raw);
                $submissions[$submission->externalId] = $submission;
            } catch (UnreadableSubmission $e) {
                $this->logger->warning('Unreadable submission skipped: {reason}', ['reason' => $e->getMessage()]);
                $run->countUnreadable();
            }
        }

        $known = $this->registrations->findByExternalIds($operation, array_keys($submissions));
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
