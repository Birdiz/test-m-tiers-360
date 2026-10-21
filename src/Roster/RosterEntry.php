<?php

namespace App\Roster;

use App\Entity\StructureType;

/**
 * An active registration as the ops team reads it.
 */
final readonly class RosterEntry
{
    /**
     * @param list<array{field: string, code: string, raw: ?string}> $anomalies
     * @param list<ReviewReason>                                      $reviewReasons
     */
    public function __construct(
        public string $externalId,
        public int $rank,
        public bool $beyondCapacity,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public StructureType $structureType,
        public ?string $structureName,
        public ?string $comment,
        public array $anomalies,
        public array $reviewReasons,
    ) {
    }
}
