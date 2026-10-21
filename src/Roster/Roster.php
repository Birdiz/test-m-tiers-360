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
    /**
     * @param list<RosterEntry> $registrations active registrations, first come first served
     */
    public function __construct(
        public Session $session,
        public RosterSummary $summary,
        public array $registrations,
    ) {
    }

    /**
     * @param list<Registration> $registrations every registration of the session, oldest first
     */
    public static function of(Session $session, array $registrations): self
    {
        $active = array_values(array_filter($registrations, static fn (Registration $r): bool => $r->isActive()));
        $capacity = $session->getCapacity();

        $entries = [];
        foreach ($active as $index => $registration) {
            $entries[] = new RosterEntry(
                externalId: $registration->getExternalId(),
                rank: $index + 1,
                beyondCapacity: $index + 1 > $capacity,
                firstName: $registration->getFirstName(),
                lastName: $registration->getLastName(),
                email: $registration->getEmail(),
                phone: $registration->getPhone(),
                structureType: $registration->getStructureType(),
                structureName: $registration->getStructureName(),
                comment: $registration->getComment(),
                anomalies: $registration->getAnomalies(),
            );
        }

        return new self($session, new RosterSummary(
            active: \count($active),
            cancelled: \count(array_filter($registrations, static fn (Registration $r): bool => !$r->isRemoved() && RegistrationStatus::Cancelled === $r->getStatus())),
            removed: \count(array_filter($registrations, static fn (Registration $r): bool => $r->isRemoved())),
            remainingSeats: max(0, $capacity - \count($active)),
            overbookedBy: max(0, \count($active) - $capacity),
        ), $entries);
    }
}
