<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One form of the external tool = one operation.
 *
 * Its constraints are the database's, nothing more: what the column cannot hold (ADR 0014).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['external_form_id'])]
class Operation
{
    public const EXTERNAL_ID_LENGTH = 64;
    public const TITLE_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: self::EXTERNAL_ID_LENGTH)]
        #[Assert\Length(max: self::EXTERNAL_ID_LENGTH)]
        private string $externalFormId,
        #[ORM\Column(length: self::TITLE_LENGTH)]
        #[Assert\Length(max: self::TITLE_LENGTH)]
        private string $title,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalFormId(): string
    {
        return $this->externalFormId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }
}
