<?php

namespace App\Tests\Import;

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
            [...$answers, 'session' => ['id' => 'ses_01', 'label' => 'Bourg-en-Bresse — 17 novembre', 'date' => '2026-11-17', 'location' => 'Espace des métiers', 'capacity' => 2]],
            ['id' => $id, 'created_at' => $createdAt, 'updated_at' => $createdAt, ...$submission],
        );
    }
}
