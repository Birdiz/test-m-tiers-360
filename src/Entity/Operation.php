<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One form of the external tool = one operation.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['external_form_id'])]
class Operation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $externalFormId,
        #[ORM\Column(length: 255)]
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
