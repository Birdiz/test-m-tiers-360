<?php

namespace App\Entity;

use App\Enum\RegistrationStatus;
use App\Enum\StructureType;
use App\Import\Submission\Anomaly;
use App\Import\Submission\AnomalyCode;
use App\Import\Submission\NormalizedSubmission;
use App\Repository\RegistrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Owned by the import: every field comes from the source (ADR 0007).
 *
 * Its constraints are the database's, nothing more: what the column cannot hold (ADR 0014).
 */
#[ORM\Entity(repositoryClass: RegistrationRepository::class)]
#[ORM\UniqueConstraint(columns: ['operation_id', 'external_id'])]
#[ORM\Index(columns: ['session_id'])]
class Registration
{
    public const EXTERNAL_ID_LENGTH = 64;
    public const NAME_LENGTH = 255;
    public const EMAIL_LENGTH = 255;
    public const PHONE_LENGTH = 32;
    public const STRUCTURE_NAME_LENGTH = 255;
    /** Postgres has no year 0 nor any year before it, as Doctrine writes dates. */
    private const FIRST_STORABLE_DAY = '0001-01-01';

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
    #[Assert\GreaterThanOrEqual(self::FIRST_STORABLE_DAY)]
    private \DateTimeImmutable $sourceCreatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\GreaterThanOrEqual(self::FIRST_STORABLE_DAY)]
    private \DateTimeImmutable $sourceUpdatedAt;

    /**
     * When it took its place in the current session's queue: submitted there, or moved there from another
     * session (the source version that showed the move). First come, first served counts from here (ADR 0004).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedSessionAt;

    #[ORM\Column(length: self::NAME_LENGTH)]
    #[Assert\Length(max: self::NAME_LENGTH)]
    private string $firstName;

    #[ORM\Column(length: self::NAME_LENGTH)]
    #[Assert\Length(max: self::NAME_LENGTH)]
    private string $lastName;

    #[ORM\Column(length: self::EMAIL_LENGTH, nullable: true)]
    #[Assert\Length(max: self::EMAIL_LENGTH)]
    private ?string $email;

    #[ORM\Column(length: self::PHONE_LENGTH, nullable: true)]
    #[Assert\Length(max: self::PHONE_LENGTH)]
    private ?string $phone;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(self::FIRST_STORABLE_DAY)]
    private ?\DateTimeImmutable $birthDate;

    #[ORM\Column(length: 32, enumType: StructureType::class)]
    private StructureType $structureType;

    #[ORM\Column(length: self::STRUCTURE_NAME_LENGTH, nullable: true)]
    #[Assert\Length(max: self::STRUCTURE_NAME_LENGTH)]
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
        #[ORM\Column(length: self::EXTERNAL_ID_LENGTH)]
        #[Assert\Length(max: self::EXTERNAL_ID_LENGTH)]
        private string $externalId,
        NormalizedSubmission $submission,
        Session $session,
        int $runId,
    ) {
        $this->copy($submission, $session);
        $this->joinedSessionAt = $submission->sourceCreatedAt;
        $this->lastSeenRunId = $runId;
    }

    /**
     * Records that the source still has this registration, and takes its version unless it is older than ours.
     *
     * @return bool whether the source version was applied
     */
    public function seenInSource(NormalizedSubmission $submission, Session $session, int $runId): bool
    {
        $this->stillInSource($runId);

        if ($submission->sourceUpdatedAt <= $this->sourceUpdatedAt) {
            return false;
        }

        if ($session->getId() !== $this->session->getId()) {
            $this->joinedSessionAt = $submission->sourceUpdatedAt;
        }
        $this->copy($submission, $session);

        return true;
    }

    /**
     * Records that the source still has this registration, without taking its version (it could not be read).
     */
    public function stillInSource(int $runId): void
    {
        $this->lastSeenRunId = $runId;
        $this->removedAt = null;
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
        $this->anomalies = array_map(static fn (Anomaly $anomaly): array => $anomaly->jsonSerialize(), $submission->anomalies);
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

    /**
     * Neither a valid email nor a phone: nobody can send a reminder or a cancellation.
     */
    public function isUnreachable(): bool
    {
        return null === $this->email && null === $this->phone;
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
     * @return list<Anomaly>
     */
    public function getAnomalies(): array
    {
        return array_map(
            static fn (array $anomaly): Anomaly => new Anomaly($anomaly['field'], AnomalyCode::from($anomaly['code']), $anomaly['raw']),
            $this->anomalies,
        );
    }
}
