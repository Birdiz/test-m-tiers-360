<?php

declare(strict_types=1);

namespace App\Entity;

use App\Import\SessionSnapshot;
use App\Repository\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\UniqueConstraint(columns: ['operation_id', 'external_id'])]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $location;

    #[ORM\Column]
    private int $capacity;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Operation $operation,
        #[ORM\Column(length: 64)]
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
