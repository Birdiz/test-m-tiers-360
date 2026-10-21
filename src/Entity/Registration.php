<?php

namespace App\Entity;

use App\Import\Anomaly;
use App\Import\NormalizedSubmission;
use App\Repository\RegistrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Owned by the import: every field comes from the source (ADR 0007).
 */
#[ORM\Entity(repositoryClass: RegistrationRepository::class)]
#[ORM\UniqueConstraint(columns: ['operation_id', 'external_id'])]
#[ORM\Index(columns: ['session_id'])]
class Registration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Session $session;

    #[ORM\Column(length: 16, enumType: RegistrationStatus::class)]
    private RegistrationStatus $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sourceCreatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column(length: 255)]
    private string $firstName;

    #[ORM\Column(length: 255)]
    private string $lastName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $birthDate;

    #[ORM\Column(length: 32, enumType: StructureType::class)]
    private StructureType $structureType;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $structureName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    /** @var list<array{field: string, code: string, raw: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $anomalies;

    /** Id of the last import run that saw this registration in the source. */
    #[ORM\Column]
    private int $lastSeenRunId;

    /** Set when a complete run no longer finds it in the source (ADR 0002). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Operation $operation,
        #[ORM\Column(length: 64)]
        private string $externalId,
        NormalizedSubmission $submission,
        Session $session,
        int $runId,
    ) {
        $this->copy($submission, $session);
        $this->lastSeenRunId = $runId;
    }

    /**
     * Records that the source still has this registration, and takes its version unless it is older than ours.
     *
     * @return bool whether the source version was applied
     */
    public function seenInSource(NormalizedSubmission $submission, Session $session, int $runId): bool
    {
        $this->lastSeenRunId = $runId;
        $this->removedAt = null;

        if ($submission->sourceUpdatedAt <= $this->sourceUpdatedAt) {
            return false;
        }

        $this->copy($submission, $session);

        return true;
    }

    private function copy(NormalizedSubmission $submission, Session $session): void
    {
        $this->session = $session;
        $this->status = $submission->status;
        $this->sourceCreatedAt = $submission->sourceCreatedAt;
        $this->sourceUpdatedAt = $submission->sourceUpdatedAt;
        $this->firstName = $submission->firstName;
        $this->lastName = $submission->lastName;
        $this->email = $submission->email;
        $this->phone = $submission->phone;
        $this->birthDate = $submission->birthDate;
        $this->structureType = $submission->structureType;
        $this->structureName = $submission->structureName;
        $this->comment = $submission->comment;
        $this->anomalies = array_map(
            static fn (Anomaly $anomaly): array => ['field' => $anomaly->field, 'code' => $anomaly->code->value, 'raw' => $anomaly->raw],
            $submission->anomalies,
        );
    }

    /**
     * Holds a seat: submitted in the source and still present there.
     */
    public function isActive(): bool
    {
        return RegistrationStatus::Submitted === $this->status && null === $this->removedAt;
    }

    /**
     * Identifies the person behind a registration, as far as the source allows: same email, first name and birth
     * date. Never the email alone, siblings share one (ADR 0001). Null without an email.
     */
    public function personKey(): ?string
    {
        return null === $this->email ? null : implode('|', [$this->email, mb_strtolower($this->firstName), $this->birthDate?->format('Y-m-d')]);
    }

    public function isRemoved(): bool
    {
        return null !== $this->removedAt;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getStatus(): RegistrationStatus
    {
        return $this->status;
    }

    public function getSourceCreatedAt(): \DateTimeImmutable
    {
        return $this->sourceCreatedAt;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function getStructureType(): StructureType
    {
        return $this->structureType;
    }

    public function getStructureName(): ?string
    {
        return $this->structureName;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @return list<array{field: string, code: string, raw: ?string}>
     */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }
}
