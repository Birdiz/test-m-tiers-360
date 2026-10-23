<?php

namespace App\Import\Submission;

/**
 * A field the import could not understand. Carries only the offending raw value (ADR 0003).
 */
final readonly class Anomaly implements \JsonSerializable
{
    public function __construct(
        public string $field,
        public AnomalyCode $code,
        public ?string $raw,
    ) {
    }

    /**
     * @return array{field: string, code: string, raw: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['field' => $this->field, 'code' => $this->code->value, 'raw' => $this->raw];
    }
}
