<?php

declare(strict_types=1);

namespace App\Import\Submission;

/**
 * A submission that cannot be tied to a registration or a session. Unlike a dirty answer, it is not imported,
 * but it is reported (ADR 0003).
 */
final class UnreadableSubmission extends \RuntimeException
{
    public function __construct(
        string $reason,
        public readonly ?string $externalId = null,
    ) {
        parent::__construct($reason);
    }
}
