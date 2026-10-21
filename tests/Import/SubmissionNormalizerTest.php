<?php

namespace App\Tests\Import;

use App\Entity\RegistrationStatus;
use App\Entity\StructureType;
use App\Import\SubmissionNormalizer;
use App\Import\Anomaly;
use App\Import\AnomalyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmissionNormalizerTest extends TestCase
{
    public function testCleanSubmissionIsNormalizedWithoutAnomaly(): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(self::submission());

        self::assertSame('sub_k3m9qz7tpe', $normalized->externalId);
        self::assertSame(RegistrationStatus::Submitted, $normalized->status);
        self::assertSame('2026-09-14T07:29:00+00:00', $normalized->sourceCreatedAt->format(DATE_ATOM));
        self::assertSame('Léa', $normalized->firstName);
        self::assertSame('Martin', $normalized->lastName);
        self::assertSame('lea.martin@example.com', $normalized->email);
        self::assertSame('+33639981234', $normalized->phone);
        self::assertSame('2009-05-21', $normalized->birthDate?->format('Y-m-d'));
        self::assertSame(StructureType::Lycee, $normalized->structureType);
        self::assertSame('Lycée des Quatre-Vallées', $normalized->structureName);
        self::assertNull($normalized->comment);
        self::assertSame('ses_01', $normalized->session->externalId);
        self::assertSame(80, $normalized->session->capacity);
        self::assertSame('2026-11-17', $normalized->session->date->format('Y-m-d'));
        self::assertSame([], $normalized->anomalies);
    }

    #[DataProvider('emails')]
    public function testEmailIsTrimmedAndLowercasedOrFlagged(mixed $raw, ?string $expected, ?Anomaly $anomaly): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(self::submission(['email' => $raw]));

        self::assertSame($expected, $normalized->email);
        self::assertEquals(null === $anomaly ? [] : [$anomaly], $normalized->anomalies);
    }

    /**
     * @return iterable<string, array{mixed, ?string, ?Anomaly}>
     */
    public static function emails(): iterable
    {
        yield 'upper case' => ['EMMA.HENRY@MAIL.EXAMPLE', 'emma.henry@mail.example', null];
        yield 'surrounding spaces' => [' Louise.michel@example.com ', 'louise.michel@example.com', null];
        yield 'null' => [null, null, new Anomaly('email', AnomalyCode::Missing, null)];
        yield 'text instead of an address' => ["pas d'adresse", null, new Anomaly('email', AnomalyCode::Invalid, "pas d'adresse")];
        yield 'no domain' => ['sofia.robert@', null, new Anomaly('email', AnomalyCode::Invalid, 'sofia.robert@')];
        yield 'space inside' => ['kylian bernard@example.com', null, new Anomaly('email', AnomalyCode::Invalid, 'kylian bernard@example.com')];
    }

    public function testMissingEmailKeyIsFlagged(): void
    {
        $submission = self::submission();
        unset($submission['answers']['email']);

        $normalized = (new SubmissionNormalizer())->normalize($submission);

        self::assertNull($normalized->email);
        self::assertEquals([new Anomaly('email', AnomalyCode::Missing, null)], $normalized->anomalies);
    }

    /**
     * A submission as served by the form API (docs/api-formulaire.md), with optional overrides of its answers.
     *
     * @param array<string, mixed> $answers
     * @param array<string, mixed> $submission
     *
     * @return array<string, mixed>
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
}
