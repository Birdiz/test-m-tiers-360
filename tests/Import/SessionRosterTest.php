<?php

namespace App\Tests\Import;

use App\Enum\ImportRunStatus;
use App\Enum\StructureType;
use App\Roster\ReviewReason;
use App\Roster\RosterEntry;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Seam S1, read side: what the ops team sees for a session after an import.
 */
final class SessionRosterTest extends ImportTestCase
{
    use ClockSensitiveTrait;

    public function testActiveRegistrationsAreRankedFirstComeFirstServedAgainstCapacity(): void
    {
        $this->formApi()->serve([
            self::registration('sub_c', '2026-09-03T10:00:00Z'),
            self::registration('sub_a', '2026-09-01T10:00:00Z'),
            self::registration('sub_x', '2026-09-01T09:00:00Z', ['status' => 'cancelled']),
            self::registration('sub_b', '2026-09-02T10:00:00Z'),
        ]);

        $this->runImport();
        $roster = $this->roster('ses_01');

        self::assertSame([['sub_a', 1, false], ['sub_b', 2, false], ['sub_c', 3, true]], array_map(
            static fn (RosterEntry $entry): array => [$entry->externalId, $entry->rank, $entry->beyondCapacity],
            $roster->registrations,
        ));
        self::assertSame([0, 1], [$roster->summary->remainingSeats, $roster->summary->overbookedBy]);
    }

    public function testCancellationLetsTheNextRegistrationIn(): void
    {
        $this->formApi()->serve([
            self::registration('sub_a', '2026-09-01T10:00:00Z'),
            self::registration('sub_b', '2026-09-02T10:00:00Z'),
            self::registration('sub_c', '2026-09-03T10:00:00Z'),
        ]);
        $this->runImport();
        $this->formApi()->serve([
            self::registration('sub_a', '2026-09-01T10:00:00Z', ['status' => 'cancelled', 'updated_at' => '2026-10-01T10:00:00Z']),
            self::registration('sub_b', '2026-09-02T10:00:00Z'),
            self::registration('sub_c', '2026-09-03T10:00:00Z'),
        ]);

        $this->runImport();

        self::assertFalse($this->roster('ses_01')->registrations[1]->beyondCapacity);
        self::assertSame([0, 0], [$this->roster('ses_01')->summary->remainingSeats, $this->roster('ses_01')->summary->overbookedBy]);
    }

    public function testRegistrationMovedFromAnotherSessionQueuesFromWhenItArrived(): void
    {
        // As sub_haczwpup6e in the kit's data: registered early elsewhere, moved here later.
        $oyonnax = ['session' => ['id' => 'ses_02', 'label' => 'Oyonnax — 18 novembre', 'date' => '2026-11-18', 'location' => 'Centre culturel', 'capacity' => 80]];
        $this->formApi()->serve([
            self::registration('sub_mover', '2026-08-15T10:00:00Z', answers: $oyonnax),
            self::registration('sub_early', '2026-09-01T10:00:00Z'),
            self::registration('sub_late', '2026-09-20T10:00:00Z'),
        ]);
        $this->runImport();
        $this->formApi()->serve([
            self::registration('sub_mover', '2026-08-15T10:00:00Z', ['updated_at' => '2026-10-01T10:00:00Z']),
            self::registration('sub_early', '2026-09-01T10:00:00Z'),
            self::registration('sub_late', '2026-09-20T10:00:00Z'),
        ]);

        $this->runImport();

        self::assertSame([['sub_early', false], ['sub_late', false], ['sub_mover', true]], array_map(
            static fn (RosterEntry $entry): array => [$entry->externalId, $entry->beyondCapacity],
            $this->roster('ses_01')->registrations,
        ));
    }

    public function testSamePersonTwiceIsAProbableDuplicateButSiblingsSharingAnEmailAreNot(): void
    {
        $this->formApi()->serve([
            // Same person, email typed with different casing (as Emma Henry in the kit's data).
            self::registration('sub_emma1', '2026-09-01T10:00:00Z', answers: self::person('Emma', 'emma.henry@mail.example', '2009-05-24')),
            self::registration('sub_emma2', '2026-09-02T10:00:00Z', answers: self::person('Emma', 'EMMA.HENRY@MAIL.EXAMPLE', '2009-05-24')),
            // Siblings sharing the family address (as the Girard children).
            self::registration('sub_manon', '2026-09-03T10:00:00Z', answers: self::person('Manon', 'famille.girard@example.org', '2010-03-14')),
            self::registration('sub_hugo', '2026-09-04T10:00:00Z', answers: self::person('Hugo', 'famille.girard@example.org', '2011-06-27')),
        ]);

        $this->runImport();
        $roster = $this->roster('ses_01');

        self::assertSame([4, 3], [$roster->summary->active, $roster->summary->estimatedPeople]);
        self::assertSame([
            'sub_emma1' => [ReviewReason::ProbableDuplicate],
            'sub_emma2' => [ReviewReason::ProbableDuplicate],
            'sub_manon' => [],
            'sub_hugo' => [],
        ], self::reviewReasons($roster->registrations));
    }

    public function testPersonAlsoRegisteredOnAnotherSessionIsFlagged(): void
    {
        $this->formApi()->serve([
            self::registration('sub_theo1', '2026-09-01T10:00:00Z', answers: self::person('Théo', 'theo.robin@example.test', '2009-10-02')),
            self::registration('sub_theo2', '2026-09-02T10:00:00Z', answers: [...self::person('Théo', 'theo.robin@example.test', '2009-10-02'), 'session' => [
                'id' => 'ses_02', 'label' => 'Oyonnax — 18 novembre', 'date' => '2026-11-18', 'location' => 'Centre culturel', 'capacity' => 80,
            ]]),
        ]);

        $this->runImport();

        self::assertSame(['sub_theo1' => [ReviewReason::OtherSession]], self::reviewReasons($this->roster('ses_01')->registrations));
        self::assertSame(['sub_theo2' => [ReviewReason::OtherSession]], self::reviewReasons($this->roster('ses_02')->registrations));
    }

    public function testAgeIsTakenOnTheSessionDate(): void
    {
        // The session is on 2026-11-17.
        $this->formApi()->serve([
            self::registration('sub_17', '2026-09-01T10:00:00Z', answers: ['birth_date' => '2008-11-18']),
            self::registration('sub_18', '2026-09-02T10:00:00Z', answers: ['birth_date' => '2008-11-17']),
            self::registration('sub_unknown', '2026-09-03T10:00:00Z', answers: ['birth_date' => '1900-01-01']),
        ]);

        $this->runImport();
        $roster = $this->roster('ses_01');

        self::assertSame([17, 18, null], array_map(static fn (RosterEntry $entry): ?int => $entry->age, $roster->registrations));
        self::assertSame(['minors' => 1, 'adults' => 1, 'unknown' => 1], $roster->summary->ages);
    }

    public function testRegistrationsToReviewSayWhy(): void
    {
        $this->formApi()->serve([
            self::registration('sub_clean', '2026-09-01T10:00:00Z', answers: self::person('Léa', 'lea@example.com', '2009-05-21')),
            self::registration('sub_unreachable', '2026-09-02T10:00:00Z', answers: ['email' => null, 'phone' => null]),
            self::registration('sub_phone_only', '2026-09-03T10:00:00Z', answers: ['email' => "pas d'adresse"]),
            self::registration('sub_comment', '2026-09-04T10:00:00Z', answers: [...self::person('Nina', 'nina@example.com', '2008-11-06'), 'comment' => "Besoin d'un accès PMR."]),
        ]);

        $this->runImport();
        $summary = $this->roster('ses_01')->summary;

        self::assertSame(1, $summary->unreachable);
        self::assertSame([
            'sub_unreachable' => [ReviewReason::Unreachable, ReviewReason::Anomaly],
            'sub_phone_only' => [ReviewReason::Anomaly],
            'sub_comment' => [ReviewReason::Comment],
        ], $summary->toReview);
    }

    public function testRegistrationsAreCountedByStructure(): void
    {
        $this->formApi()->serve([
            self::registration('sub_1', '2026-09-01T10:00:00Z', answers: ['structure_name' => 'Lycée des Quatre-Vallées']),
            self::registration('sub_2', '2026-09-02T10:00:00Z', answers: ['structure_type' => 'mission_locale', 'structure_name' => 'Mission locale de Belley']),
            self::registration('sub_3', '2026-09-03T10:00:00Z', answers: ['structure_name' => 'Lycée des Quatre-Vallées']),
        ]);

        $this->runImport();

        self::assertSame([
            ['type' => StructureType::Lycee, 'name' => 'Lycée des Quatre-Vallées', 'count' => 2],
            ['type' => StructureType::MissionLocale, 'name' => 'Mission locale de Belley', 'count' => 1],
        ], $this->roster('ses_01')->summary->byStructure);
    }

    public function testDataIsAsOfTheLastCompleteRunAndAFailedRunShows(): void
    {
        self::mockTime('2026-10-20 06:00:00');
        $this->formApi()->serve([self::registration('sub_a', '2026-09-01T10:00:00Z')]);
        $this->runImport();
        self::mockTime('2026-10-20 07:00:00');
        $this->formApi()->script(1, ...array_fill(0, 4, FakeFormApi::serverError()));
        $this->runImport();

        $summary = $this->roster('ses_01')->summary;

        self::assertSame('2026-10-20 06:00:00', $summary->dataAsOf?->format('Y-m-d H:i:s'));
        self::assertSame(ImportRunStatus::Failed, $summary->lastRunStatus);
        self::assertSame('2026-10-20 07:00:00', $summary->lastRunAt?->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function person(string $firstName, string $email, string $birthDate): array
    {
        return ['first_name' => $firstName, 'email' => $email, 'birth_date' => $birthDate];
    }

    /**
     * @param list<RosterEntry> $entries
     *
     * @return array<string, list<ReviewReason>>
     */
    private static function reviewReasons(array $entries): array
    {
        return array_combine(
            array_map(static fn (RosterEntry $entry): string => $entry->externalId, $entries),
            array_map(static fn (RosterEntry $entry): array => $entry->reviewReasons, $entries),
        );
    }

    /**
     * A submission for a session of 2 seats.
     *
     * @param array<string, mixed> $submission
     * @param array<string, mixed> $answers
     *
     * @return array<string, mixed>
     */
    private static function registration(string $id, string $createdAt, array $submission = [], array $answers = []): array
    {
        return Submissions::submission(
            ['session' => ['id' => 'ses_01', 'label' => 'Bourg-en-Bresse — 17 novembre', 'date' => '2026-11-17', 'location' => 'Espace des métiers', 'capacity' => 2], ...$answers],
            ['id' => $id, 'created_at' => $createdAt, 'updated_at' => $createdAt, ...$submission],
        );
    }
}
