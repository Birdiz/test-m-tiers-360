<?php

declare(strict_types=1);

namespace App\Roster;

use App\Enum\StructureType;
use App\Import\Submission\Anomaly;

/**
 * An active registration as the ops team reads it.
 */
final readonly class RosterEntry implements \JsonSerializable
{
    /**
     * @param list<Anomaly>      $anomalies
     * @param list<ReviewReason> $reviewReasons
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
        /** A probable duplicate of an entry ranked before it: not one more person. */
        public bool $isRepeat,
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
