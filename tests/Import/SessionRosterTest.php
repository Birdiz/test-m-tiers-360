<?php

namespace App\Tests\Import;

use App\Roster\ReviewReason;
use App\Roster\RosterEntry;

/**
 * Seam S1, read side: what the ops team sees for a session after an import.
 */
final class SessionRosterTest extends ImportTestCase
{
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
        return SubmissionNormalizerTest::submission(
            ['session' => ['id' => 'ses_01', 'label' => 'Bourg-en-Bresse — 17 novembre', 'date' => '2026-11-17', 'location' => 'Espace des métiers', 'capacity' => 2], ...$answers],
            ['id' => $id, 'created_at' => $createdAt, 'updated_at' => $createdAt, ...$submission],
        );
    }
}
