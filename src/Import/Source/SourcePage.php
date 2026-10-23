<?php

namespace App\Import\Source;

final readonly class SourcePage
{
    /**
     * @param list<array<string, mixed>> $submissions raw submissions, as served
     */
    public function __construct(
        public string $formId,
        public string $formTitle,
        public array $submissions,
        public int $total,
    ) {
    }
}
