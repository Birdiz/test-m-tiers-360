<?php

namespace App\Roster;

final readonly class RosterSummary
{
    public function __construct(
        public int $active,
        public int $cancelled,
        public int $removed,
        /** Active registrations, probable duplicates counted once. */
        public int $estimatedPeople,
        public int $remainingSeats,
        public int $overbookedBy,
    ) {
    }
}
