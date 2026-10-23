<?php

namespace App\Roster;

use App\Entity\ImportRun;
use App\Entity\Registration;
use App\Entity\Session;
use App\Enum\RegistrationStatus;
use App\Enum\StructureType;

/**
 * What the ops team reads for one session. Computed on read (ADRs 0004, 0008).
 */
final readonly class Roster implements \JsonSerializable
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
     * @param list<Registration> $registrations every registration of the session, in queue order
     * @param list<Registration> $elsewhere     registrations of other sessions sharing an email with this one
     */
    public static function of(Session $session, array $registrations, array $elsewhere, ?ImportRun $lastRun, ?ImportRun $lastCompletedRun): self
    {
        $active = array_values(array_filter($registrations, static fn (Registration $r): bool => $r->isActive()));
        $entries = self::buildEntries($session, $active, array_values(array_filter($elsewhere, static fn (Registration $r): bool => $r->isActive())));

        $toReview = [];
        foreach ($entries as $entry) {
            if ([] !== $entry->reviewReasons) {
                $toReview[$entry->externalId] = $entry->reviewReasons;
            }
        }
        $ages = array_map(static fn (RosterEntry $e): ?int => $e->age, $entries);

        return new self($session, new RosterSummary(
            active: \count($active),
            cancelled: \count(array_filter($registrations, static fn (Registration $r): bool => !$r->isRemoved() && RegistrationStatus::Cancelled === $r->getStatus())),
            removed: \count(array_filter($registrations, static fn (Registration $r): bool => $r->isRemoved())),
            estimatedPeople: \count($active) - \count(array_filter($entries, static fn (RosterEntry $e): bool => $e->isRepeat)),
            remainingSeats: max(0, $session->getCapacity() - \count($active)),
            overbookedBy: max(0, \count($active) - $session->getCapacity()),
            byStructure: self::groupByStructure($active),
            ages: [
                'minors' => \count(array_filter($ages, static fn (?int $age): bool => null !== $age && $age < 18)),
                'adults' => \count(array_filter($ages, static fn (?int $age): bool => null !== $age && $age >= 18)),
                'unknown' => \count(array_filter($ages, static fn (?int $age): bool => null === $age)),
            ],
            unreachable: \count(array_filter($active, static fn (Registration $r): bool => $r->isUnreachable())),
            toReview: $toReview,
            dataAsOf: $lastCompletedRun?->getFinishedAt(),
            lastRunStatus: $lastRun?->getStatus(),
            lastRunAt: $lastRun?->getFinishedAt(),
        ), $entries);
    }

    /**
     * @param list<Registration> $active          active registrations of the session, in queue order
     * @param list<Registration> $activeElsewhere active registrations of other sessions sharing an email
     *
     * @return list<RosterEntry>
     */
    private static function buildEntries(Session $session, array $active, array $activeElsewhere): array
    {
        $peopleHere = array_count_values(array_filter(array_map(static fn (Registration $r): ?string => $r->personKey(), $active)));
        $peopleElsewhere = array_flip(array_filter(array_map(static fn (Registration $r): ?string => $r->personKey(), $activeElsewhere)));
        $alreadyListed = [];

        $entries = [];
        foreach ($active as $index => $registration) {
            $person = $registration->personKey();
            $reasons = array_values(array_filter([
                null !== $person && $peopleHere[$person] > 1 ? ReviewReason::ProbableDuplicate : null,
                null !== $person && isset($peopleElsewhere[$person]) ? ReviewReason::OtherSession : null,
                $registration->isUnreachable() ? ReviewReason::Unreachable : null,
                [] !== $registration->getAnomalies() ? ReviewReason::Anomaly : null,
                null !== $registration->getComment() ? ReviewReason::Comment : null,
            ]));

            $entries[] = new RosterEntry(
                externalId: $registration->getExternalId(),
                rank: $index + 1,
                beyondCapacity: $index + 1 > $session->getCapacity(),
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
                isRepeat: null !== $person && isset($alreadyListed[$person]),
            );
            if (null !== $person) {
                $alreadyListed[$person] = true;
            }
        }

        return $entries;
    }

    /**
     * @param list<Registration> $active
     *
     * @return list<array{type: StructureType, name: ?string, count: int}>
     */
    private static function groupByStructure(array $active): array
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'session' => [
                'id' => $this->session->getExternalId(),
                'label' => $this->session->getLabel(),
                'date' => $this->session->getDate()->format('Y-m-d'),
                'location' => $this->session->getLocation(),
                'capacity' => $this->session->getCapacity(),
            ],
            'summary' => $this->summary,
            'registrations' => $this->registrations,
        ];
    }
}
