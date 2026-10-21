<?php

namespace App\Entity;

use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
class ImportRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: ImportRunStatus::class)]
    private ImportRunStatus $status = ImportRunStatus::Running;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $seen = 0;

    #[ORM\Column]
    private int $created = 0;

    #[ORM\Column]
    private int $updated = 0;

    #[ORM\Column]
    private int $removed = 0;

    #[ORM\Column]
    private int $unreadable = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $startedAt,
    ) {
    }

    public function countSeen(): void
    {
        ++$this->seen;
    }

    public function countCreated(): void
    {
        ++$this->created;
    }

    public function countUpdated(): void
    {
        ++$this->updated;
    }

    public function countUnreadable(): void
    {
        ++$this->unreadable;
    }

    public function complete(int $removed, \DateTimeImmutable $at): void
    {
        $this->removed = $removed;
        $this->finish(ImportRunStatus::Completed, $at);
    }

    public function endIncomplete(string $reason, \DateTimeImmutable $at): void
    {
        $this->error = $reason;
        $this->finish(ImportRunStatus::Incomplete, $at);
    }

    public function fail(string $error, \DateTimeImmutable $at): void
    {
        $this->error = $error;
        $this->finish(ImportRunStatus::Failed, $at);
    }

    private function finish(ImportRunStatus $status, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->finishedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ImportRunStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getSeen(): int
    {
        return $this->seen;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getRemoved(): int
    {
        return $this->removed;
    }

    public function getUnreadable(): int
    {
        return $this->unreadable;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
