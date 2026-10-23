<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportRun;
use App\Entity\Operation;
use App\Repository\ImportRunRepository;
use App\Repository\RegistrationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What one import run saw of the source, and what it concludes: whether the run is complete, and which
 * registrations two complete runs in a row did not find (ADRs 0002, 0020). The only writer of "seen by run N".
 */
final class RunTally
{
    private ?Operation $operation = null;
    private ?int $total = null;
    private bool $totalChanged = false;
    /**
     * Unreadable submissions that match no registration: in the source, but not countable in our table. Kept by id
     * so a page shift does not count one twice; few, since the source rarely serves one.
     *
     * @var array<string, true>
     */
    private array $unmatched = [];
    // Unreadable submissions without an id: each one counts, nothing tells two apart.
    private int $withoutId = 0;

    public function __construct(
        private readonly ImportRun $run,
        private readonly RegistrationRepository $registrations,
        private readonly ImportRunRepository $runs,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Records a page of the source in its transaction, once its registrations are written.
     *
     * @param list<string>  $readable   ids of the page's readable submissions, all registrations by now
     * @param list<?string> $unreadable ids of its unreadable ones, null when a submission has none
     */
    public function recordPage(Operation $operation, int $total, array $readable, array $unreadable): void
    {
        $this->operation = $operation;
        $this->totalChanged = $this->totalChanged || (null !== $this->total && $this->total !== $total);
        $this->total ??= $total;

        $ids = [];
        foreach ($unreadable as $externalId) {
            if (null === $externalId) {
                ++$this->withoutId;
            } else {
                $ids[] = $externalId;
            }
        }
        $this->registrations->markSeen($operation, [...$readable, ...$ids], $this->run);

        $known = $this->registrations->findKnownExternalIds($operation, $ids);
        foreach (array_diff($ids, $known) as $externalId) {
            $this->unmatched[$externalId] = true;
        }
        // Unreadable on an earlier page, readable now: a registration, counted with the others.
        foreach ($readable as $externalId) {
            unset($this->unmatched[$externalId]);
        }

        $this->run->recordSeen($this->registrations->countSeenIn($operation, $this->run) + \count($this->unmatched) + $this->withoutId);
    }

    public function fail(string $reason): void
    {
        $this->logger->error('Import run failed: {error}', ['error' => $reason]);
        $this->run->fail($reason, $this->clock->now());
    }

    /**
     * Ends a run that read every page: complete, with its removals, or incomplete, removing nothing. Expects a
     * transaction, so that removals are only written with the completed status (ADR 0016).
     */
    public function finish(): void
    {
        if (null === $this->operation) {
            throw new \LogicException('A run that read every page recorded at least one: FormApi serves the first page or fails.');
        }

        $seen = $this->run->getSeen();
        if ($this->totalChanged || $seen !== $this->total) {
            $this->logger->warning('Import run incomplete: saw {seen} of {total} submissions, nothing removed.', ['seen' => $seen, 'total' => $this->total]);
            $this->run->endIncomplete(\sprintf('Saw %d submissions, the source announced %s.', $seen, $this->totalChanged ? 'a changing total' : $this->total), $this->clock->now());

            return;
        }

        // The count cannot catch a deletion and an insertion that cancel out while pages shift: a registration
        // skipped that way is only removed if the previous complete run missed it too (ADR 0002).
        $previous = $this->runs->lastCompletedBefore($this->run);
        $removed = null === $previous ? 0 : $this->registrations->removeNotSeenSince($this->operation, $previous, $this->clock->now());
        $this->run->complete($removed, $this->clock->now());
    }
}
