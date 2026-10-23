<?php

declare(strict_types=1);

namespace App\Roster;

use App\Enum\ImportRunStatus;
use App\Enum\StructureType;

final readonly class RosterSummary implements \JsonSerializable
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'active' => $this->active,
            'cancelled' => $this->cancelled,
            'removed' => $this->removed,
            'estimatedPeople' => $this->estimatedPeople,
            'remainingSeats' => $this->remainingSeats,
            'overbookedBy' => $this->overbookedBy,
            'byStructure' => $this->byStructure,
            'ages' => $this->ages,
            'unreachable' => $this->unreachable,
            'toReview' => (object) $this->toReview,
            'data' => [
                'asOf' => $this->dataAsOf?->format(\DATE_ATOM),
                'lastRunStatus' => $this->lastRunStatus,
                'lastRunAt' => $this->lastRunAt?->format(\DATE_ATOM),
            ],
        ];
    }
}
