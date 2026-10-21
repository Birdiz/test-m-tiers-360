<?php

namespace App\Roster;

use App\Entity\ImportRunStatus;
use App\Entity\StructureType;

final readonly class RosterSummary
{
    /**
     * @param list<array{type: StructureType, name: ?string, count: int}> $byStructure most represented first
     * @param array{minors: int, adults: int, unknown: int}                $ages        on the session date
     * @param array<string, list<ReviewReason>>                          $toReview    by registration external id
     */
    public function __construct(
        public int $active,
        public int $cancelled,
        public int $removed,
        /** Active registrations, probable duplicates counted once. */
        public int $estimatedPeople,
        public int $remainingSeats,
        public int $overbookedBy,
        public array $byStructure,
        public array $ages,
        public int $unreachable,
        public array $toReview,
        /** When the data was last fully in sync with the source. */
        public ?\DateTimeImmutable $dataAsOf,
        /** A failed or incomplete last run means the data may be stale. */
        public ?ImportRunStatus $lastRunStatus,
        public ?\DateTimeImmutable $lastRunAt,
    ) {
    }
}
