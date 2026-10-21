<?php

namespace App\Roster;

use App\Entity\StructureType;

/**
 * An active registration as the ops team reads it.
 */
final readonly class RosterEntry implements \JsonSerializable
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
        /** On the session date; null when the birth date is unknown. */
        public ?int $age,
        public StructureType $structureType,
        public ?string $structureName,
        public ?string $comment,
        public array $anomalies,
        public array $reviewReasons,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->externalId,
            'rank' => $this->rank,
            'beyondCapacity' => $this->beyondCapacity,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'age' => $this->age,
            'structure' => ['type' => $this->structureType, 'name' => $this->structureName],
            'comment' => $this->comment,
            'anomalies' => $this->anomalies,
            'reviewReasons' => $this->reviewReasons,
        ];
    }
}
