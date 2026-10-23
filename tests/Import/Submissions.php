<?php

namespace App\Tests\Import;

/**
 * Builds submissions shaped like the form API's (docs/api-formulaire.md) for the tests.
 *
 * @phpstan-type RawSubmission array{id: mixed, status: mixed, created_at: mixed, updated_at: mixed, answers: array<string, mixed>}
 */
final class Submissions
{
    /**
     * A submission as served by the form API (docs/api-formulaire.md), with optional overrides of its answers.
     *
     * @param array<string, mixed>                                                      $answers
     * @param array{id?: mixed, status?: mixed, created_at?: mixed, updated_at?: mixed} $submission
     *
     * @return RawSubmission
     */
    public static function submission(array $answers = [], array $submission = []): array
    {
        return array_replace([
            'id' => 'sub_k3m9qz7tpe',
            'status' => 'submitted',
            'created_at' => '2026-09-14T07:29:00Z',
            'updated_at' => '2026-09-14T07:29:00Z',
            'answers' => array_replace([
                'first_name' => 'Léa',
                'last_name' => 'Martin',
                'email' => 'lea.martin@example.com',
                'phone' => '06 39 98 12 34',
                'birth_date' => '2009-05-21',
                'structure_type' => 'lycee',
                'structure_name' => 'Lycée des Quatre-Vallées',
                'session' => [
                    'id' => 'ses_01',
                    'label' => 'Bourg-en-Bresse — 17 novembre',
                    'date' => '2026-11-17',
                    'location' => 'Espace des métiers, Bourg-en-Bresse',
                    'capacity' => 80,
                ],
                'comment' => null,
            ], $answers),
        ], $submission);
    }

    /**
     * The submissions of one of the kit's datasets (mock-api/data/).
     *
     * @return list<RawSubmission>
     */
    public static function dataset(string $file): array
    {
        /** @var array{submissions: list<RawSubmission>} $dataset */
        $dataset = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/mock-api/data/'.$file), true, flags: \JSON_THROW_ON_ERROR);

        return $dataset['submissions'];
    }
}
