<?php

namespace App\Import\Source;

final readonly class SourcePageDto
{
    /**
     * @param list<mixed> $submissions raw submissions, as served: each one is checked by SubmissionNormalizer
     */
    public function __construct(
        public string $formId,
        public string $formTitle,
        public array $submissions,
        public int $total,
    ) {
    }
}
