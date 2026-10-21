<?php

namespace App\Roster;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Session;

/**
 * What the ops team reads for one session. Computed on read (ADRs 0004, 0008).
 */
final readonly class Roster
{
    public function __construct(
        public Session $session,
        public RosterSummary $summary,
    ) {
    }

    /**
     * @param list<Registration> $registrations every registration of the session, oldest first
     */
    public static function of(Session $session, array $registrations): self
    {
        $active = array_filter($registrations, static fn (Registration $r): bool => $r->isActive());
        $cancelled = array_filter($registrations, static fn (Registration $r): bool => !$r->isRemoved() && RegistrationStatus::Cancelled === $r->getStatus());

        return new self($session, new RosterSummary(
            active: \count($active),
            cancelled: \count($cancelled),
        ));
    }
}
