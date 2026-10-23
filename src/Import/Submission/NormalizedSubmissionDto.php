<?php

namespace App\Import\Submission;

use App\Enum\RegistrationStatus;
use App\Enum\StructureType;

final readonly class NormalizedSubmissionDto
{
    /**
     * @param list<Anomaly> $anomalies
     */
    public function __construct(
        public string $externalId,
        public RegistrationStatus $status,
        public \DateTimeImmutable $sourceCreatedAt,
        public \DateTimeImmutable $sourceUpdatedAt,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public ?\DateTimeImmutable $birthDate,
        public StructureType $structureType,
        public ?string $structureName,
        public ?string $comment,
        public SessionSnapshotDto $session,
        public array $anomalies,
    ) {
    }
}
