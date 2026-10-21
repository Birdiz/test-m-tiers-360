<?php

namespace App\Import;

use App\Entity\RegistrationStatus;
use App\Entity\StructureType;

final readonly class NormalizedSubmission
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
        public SessionSnapshot $session,
        public array $anomalies,
    ) {
    }
}
