<?php

namespace App\Roster;

final readonly class RosterSummary
{
    public function __construct(
        public int $active,
        public int $cancelled,
    ) {
    }
}
