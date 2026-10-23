<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The session as described inside a submission: the form API has no session endpoint.
 */
final readonly class SessionSnapshot
{
    public function __construct(
        public string $externalId,
        public string $label,
        public \DateTimeImmutable $date,
        public string $location,
        public int $capacity,
    ) {
    }
}
