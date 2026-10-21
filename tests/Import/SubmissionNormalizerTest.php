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

    #[DataProvider('phones')]
    public function testFrenchPhoneIsNormalizedToInternationalFormatOrFlagged(?string $raw, ?string $expected, ?Anomaly $anomaly): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(self::submission(['phone' => $raw]));

        self::assertSame($expected, $normalized->phone);
        self::assertEquals(null === $anomaly ? [] : [$anomaly], $normalized->anomalies);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?Anomaly}>
     */
    public static function phones(): iterable
    {
        yield 'international with spaces' => ['+33 6 12 34 56 78', '+33612345678', null];
        yield 'dots' => ['06.12.34.56.78', '+33612345678', null];
        yield 'spaces' => ['07 12 34 56 78', '+33712345678', null];
        yield 'digits only' => ['0612345678', '+33612345678', null];
        yield 'not given (optional)' => [null, null, null];
        yield 'blank (optional)' => ['  ', null, null];
        yield 'too short' => ['12 34', null, new Anomaly('phone', AnomalyCode::Invalid, '12 34')];
    }

    #[DataProvider('birthDates')]
    public function testBirthDateIsReadOrFlagged(mixed $raw, ?string $expected, ?Anomaly $anomaly): void
    {
        // Submitted on 2026-09-14.
        $normalized = (new SubmissionNormalizer())->normalize(self::submission(['birth_date' => $raw]));

        self::assertSame($expected, $normalized->birthDate?->format('Y-m-d'));
        self::assertEquals(null === $anomaly ? [] : [$anomaly], $normalized->anomalies);
    }

    /**
     * @return iterable<string, array{mixed, ?string, ?Anomaly}>
     */
    public static function birthDates(): iterable
    {
        yield 'French day/month/year' => ['12/03/2008', '2008-03-12', null];
        yield 'more than 100 years before submitting' => ['1900-01-01', null, new Anomaly('birth_date', AnomalyCode::Implausible, '1900-01-01')];
        yield 'after the submission' => ['2031-04-02', null, new Anomaly('birth_date', AnomalyCode::Implausible, '2031-04-02')];
        yield 'impossible day' => ['2009-02-30', null, new Anomaly('birth_date', AnomalyCode::Invalid, '2009-02-30')];
        yield 'free text' => ['mai 2009', null, new Anomaly('birth_date', AnomalyCode::Invalid, 'mai 2009')];
        yield 'missing' => [null, null, new Anomaly('birth_date', AnomalyCode::Missing, null)];
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
