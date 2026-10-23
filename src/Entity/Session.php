<?php

namespace App\Entity;

use App\Import\SessionSnapshot;
use App\Repository\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Its constraints are the database's, nothing more: what the column cannot hold (ADR 0014).
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\UniqueConstraint(columns: ['operation_id', 'external_id'])]
class Session
{
    public const EXTERNAL_ID_LENGTH = 64;
    public const LABEL_LENGTH = 255;
    public const LOCATION_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::LABEL_LENGTH)]
    #[Assert\Length(max: self::LABEL_LENGTH)]
    private string $label;

    /** Postgres has no year 0 nor any year before it, as Doctrine writes dates. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\GreaterThanOrEqual('0001-01-01')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: self::LOCATION_LENGTH)]
    #[Assert\Length(max: self::LOCATION_LENGTH)]
    private string $location;

    /** Postgres `integer`. */
    #[ORM\Column]
    #[Assert\Range(min: -2_147_483_648, max: 2_147_483_647)]
    private int $capacity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Operation $operation,
        #[ORM\Column(length: self::EXTERNAL_ID_LENGTH)]
        #[Assert\Length(max: self::EXTERNAL_ID_LENGTH)]
        private string $externalId,
        SessionSnapshot $snapshot,
    ) {
        $this->describe($snapshot);
    }

    /**
     * The form API has no session endpoint: details come from the snapshots inside submissions.
     */
    public function describe(SessionSnapshot $snapshot): void
    {
        $this->label = $snapshot->label;
        $this->date = $snapshot->date;
        $this->location = $snapshot->location;
        $this->capacity = $snapshot->capacity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }
}
