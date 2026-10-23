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
 * registrations two complete runs in a row did not find (ADRs 0002, 0013). The only writer of "seen by run N".
 */
final class RunTally
{
    private ?Operation $operation = null;
    private ?int $total = null;
    private bool $totalChanged = false;
    // Submissions that match no registration (unreadable ones): in the source, but not countable in our table.
    private int $unmatched = 0;

    public function __construct(
        private readonly ImportRun $run,
        private readonly RegistrationRepository $registrations,
        private readonly ImportRunRepository $runs,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Records a page of the source once its registrations are written, in the page's transaction.
     *
     * @param list<?string> $externalIds every submission of the page, readable or not; null when it has no id
     */
    public function recordPage(Operation $operation, int $total, array $externalIds): void
    {
        $this->operation = $operation;
        $this->totalChanged = $this->totalChanged || (null !== $this->total && $this->total !== $total);
        $this->total ??= $total;

        $ids = [];
        foreach ($externalIds as $externalId) {
            if (null === $externalId) {
                ++$this->unmatched;
            } else {
                $ids[$externalId] = $externalId;
            }
        }
        $this->unmatched += \count($ids) - $this->registrations->markSeen($operation, array_values($ids), $this->run);
    }

    public function fail(string $reason): void
    {
        $this->logger->error('Import run failed: {error}', ['error' => $reason]);
        $this->run->fail($this->seen(), $reason, $this->clock->now());
    }

    /**
     * Ends a run that read every page: complete, with its removals, or incomplete, removing nothing.
     */
    public function finish(): void
    {
        if (null === $this->operation) {
            throw new \LogicException('A run that read every page recorded at least one: FormApi serves the first page or fails.');
        }

        $seen = $this->seen();
        if ($this->totalChanged || $seen !== $this->total) {
            $this->logger->warning('Import run incomplete: saw {seen} of {total} submissions, nothing removed.', ['seen' => $seen, 'total' => $this->total]);
            $this->run->endIncomplete($seen, \sprintf('Saw %d submissions, the source announced %s.', $seen, $this->totalChanged ? 'a changing total' : $this->total), $this->clock->now());

            return;
        }

        // The count cannot catch a deletion and an insertion that cancel out while pages shift: a registration
        // skipped that way is only removed if the previous complete run missed it too (ADR 0002).
        $previous = $this->runs->lastCompletedBefore($this->run);
        $removed = null === $previous ? 0 : $this->registrations->removeNotSeenSince($this->operation, $previous, $this->clock->now());
        $this->run->complete($seen, $removed, $this->clock->now());
    }

    /**
     * Each submission once: a registration read twice because pages shifted is one row seen by this run.
     */
    private function seen(): int
    {
        return null === $this->operation ? $this->unmatched : $this->registrations->countSeenIn($this->operation, $this->run) + $this->unmatched;
    }
}
