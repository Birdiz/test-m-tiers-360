<?php

namespace App\Roster;

use App\Entity\ImportRun;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Session;
use App\Entity\StructureType;

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
     * @param list<Registration> $elsewhere     active registrations of other sessions sharing an email with this one
     */
    public static function of(Session $session, array $registrations, array $elsewhere, ?ImportRun $lastRun, ?ImportRun $lastCompletedRun): self
    {
        $active = array_values(array_filter($registrations, static fn (Registration $r): bool => $r->isActive()));
        $capacity = $session->getCapacity();

        $peopleHere = array_count_values(array_filter(array_map(static fn (Registration $r): ?string => $r->personKey(), $active)));
        $peopleElsewhere = array_flip(array_filter(array_map(static fn (Registration $r): ?string => $r->personKey(), $elsewhere)));

        $entries = [];
        foreach ($active as $index => $registration) {
            $person = $registration->personKey();
            $reasons = [];
            if (null !== $person && $peopleHere[$person] > 1) {
                $reasons[] = ReviewReason::ProbableDuplicate;
            }
            if (null !== $person && isset($peopleElsewhere[$person])) {
                $reasons[] = ReviewReason::OtherSession;
            }
            if (null === $registration->getEmail() && null === $registration->getPhone()) {
                $reasons[] = ReviewReason::Unreachable;
            }
            if ([] !== $registration->getAnomalies()) {
                $reasons[] = ReviewReason::Anomaly;
            }
            if (null !== $registration->getComment()) {
                $reasons[] = ReviewReason::Comment;
            }

            $entries[] = new RosterEntry(
                externalId: $registration->getExternalId(),
                rank: $index + 1,
                beyondCapacity: $index + 1 > $capacity,
                firstName: $registration->getFirstName(),
                lastName: $registration->getLastName(),
                email: $registration->getEmail(),
                phone: $registration->getPhone(),
                age: $registration->getBirthDate()?->diff($session->getDate())->y,
                structureType: $registration->getStructureType(),
                structureName: $registration->getStructureName(),
                comment: $registration->getComment(),
                anomalies: $registration->getAnomalies(),
                reviewReasons: $reasons,
            );
        }

        return new self($session, new RosterSummary(
            active: \count($active),
            cancelled: \count(array_filter($registrations, static fn (Registration $r): bool => !$r->isRemoved() && RegistrationStatus::Cancelled === $r->getStatus())),
            removed: \count(array_filter($registrations, static fn (Registration $r): bool => $r->isRemoved())),
            estimatedPeople: \count($active) - array_sum(array_map(static fn (int $count): int => $count - 1, $peopleHere)),
            remainingSeats: max(0, $capacity - \count($active)),
            overbookedBy: max(0, \count($active) - $capacity),
            byStructure: self::byStructure($active),
            ages: [
                'minors' => \count(array_filter($entries, static fn (RosterEntry $e): bool => null !== $e->age && $e->age < 18)),
                'adults' => \count(array_filter($entries, static fn (RosterEntry $e): bool => null !== $e->age && $e->age >= 18)),
                'unknown' => \count(array_filter($entries, static fn (RosterEntry $e): bool => null === $e->age)),
            ],
            unreachable: \count(array_filter($entries, static fn (RosterEntry $e): bool => \in_array(ReviewReason::Unreachable, $e->reviewReasons, true))),
            toReview: array_column(
                array_map(static fn (RosterEntry $e): array => [$e->externalId, $e->reviewReasons], array_filter($entries, static fn (RosterEntry $e): bool => [] !== $e->reviewReasons)),
                1,
                0,
            ),
            dataAsOf: $lastCompletedRun?->getFinishedAt(),
            lastRunStatus: $lastRun?->getStatus(),
            lastRunAt: $lastRun?->getFinishedAt(),
        ), $entries);
    }

    /**
     * @param list<Registration> $active
     *
     * @return list<array{type: StructureType, name: ?string, count: int}>
     */
    private static function byStructure(array $active): array
    {
        $groups = [];
        foreach ($active as $registration) {
            $key = $registration->getStructureType()->value.'|'.$registration->getStructureName();
            $groups[$key] ??= ['type' => $registration->getStructureType(), 'name' => $registration->getStructureName(), 'count' => 0];
            ++$groups[$key]['count'];
        }
        usort($groups, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return $groups;
    }
}
