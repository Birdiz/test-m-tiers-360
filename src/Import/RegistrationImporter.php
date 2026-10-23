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
 * One import run: reads every page of the source and applies each page in its own transaction (ADR 0009); the
 * run tally says what the run saw and how it ends (ADR 0013).
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
    ) {
    }

    public function run(): ImportRun
    {
        $run = new ImportRun($this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $tally = new RunTally($run, $this->registrations, $this->runs, $this->clock, $this->logger);
        try {
            foreach ($this->formApi->pages() as $page) {
                $this->entityManager->wrapInTransaction(function () use ($page, $run, $tally): void {
                    $this->applyPage($page, $run, $tally);
                });
            }
        } catch (ExceptionInterface|SourceUnreadable $e) {
            $tally->fail($e->getMessage());
            $this->entityManager->flush();

            return $run;
        }

        $tally->finish();
        $this->entityManager->flush();

        return $run;
    }

    private function applyPage(SourcePage $page, ImportRun $run, RunTally $tally): void
    {
        $operation = $this->operation($page);

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
            $session = $this->session($operation, $submission->session);
            $registration = $known[$externalId] ?? null;
            if (null === $registration) {
                $this->entityManager->persist(new Registration($operation, $externalId, $submission, $session));
                $run->countCreated();
            } elseif ($registration->applyVersion($submission, $session)) {
                $run->countUpdated();
            }
        }

        $this->entityManager->flush();
        // Unreadable submissions are still in the source: one we already know must not be removed.
        $tally->recordPage($operation, $page->total, [...$readable, ...$unreadable]);
        // Keep memory to one page: registrations are not needed once written.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [] as $registration) {
            $this->entityManager->detach($registration);
        }
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
