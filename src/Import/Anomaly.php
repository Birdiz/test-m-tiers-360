<?php

namespace App\Import;

/**
 * A field the import could not understand. Carries only the offending raw value (ADR 0003).
 */
final readonly class Anomaly
{
    public function __construct(
        public string $field,
        public AnomalyCode $code,
        public ?string $raw,
    ) {
    }
}
