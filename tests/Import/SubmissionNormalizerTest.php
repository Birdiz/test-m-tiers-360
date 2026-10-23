<?php

namespace App\Tests\Import;

use App\Enum\RegistrationStatus;
use App\Enum\StructureType;
use App\Import\Submission\SubmissionNormalizer;
use App\Import\Submission\UnreadableSubmission;
use App\Import\Submission\Anomaly;
use App\Import\Submission\AnomalyCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmissionNormalizerTest extends TestCase
{
    public function testCleanSubmissionIsNormalizedWithoutAnomaly(): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission());

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
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['email' => $raw]));

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
        $submission = Submissions::submission();
        unset($submission['answers']['email']);

        $normalized = (new SubmissionNormalizer())->normalize($submission);

        self::assertNull($normalized->email);
        self::assertEquals([new Anomaly('email', AnomalyCode::Missing, null)], $normalized->anomalies);
    }

    #[DataProvider('phones')]
    public function testFrenchPhoneIsNormalizedToInternationalFormatOrFlagged(?string $raw, ?string $expected, ?Anomaly $anomaly): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['phone' => $raw]));

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
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['birth_date' => $raw]));

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

    #[DataProvider('structureTypes')]
    public function testStructureTypeOutsideTheFormListIsKeptAsOther(string $raw, Anomaly $anomaly): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['structure_type' => $raw]));

        self::assertSame(StructureType::Other, $normalized->structureType);
        self::assertEquals([$anomaly], $normalized->anomalies);
    }

    /**
     * @return iterable<string, array{string, Anomaly}>
     */
    public static function structureTypes(): iterable
    {
        yield 'cfa' => ['cfa', new Anomaly('structure_type', AnomalyCode::Unknown, 'cfa')];
        yield 'free text' => ['Autre : association de quartier', new Anomaly('structure_type', AnomalyCode::Unknown, 'Autre : association de quartier')];
        yield 'empty' => ['', new Anomaly('structure_type', AnomalyCode::Missing, null)];
    }

    public function testMissingStructureNameIsFlagged(): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['structure_name' => null]));

        self::assertNull($normalized->structureName);
        self::assertEquals([new Anomaly('structure_name', AnomalyCode::Missing, null)], $normalized->anomalies);
    }

    public function testMissingNameIsFlagged(): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission(['last_name' => null]));

        self::assertSame('', $normalized->lastName);
        self::assertEquals([new Anomaly('last_name', AnomalyCode::Missing, null)], $normalized->anomalies);
    }

    public function testFreeTextIsTrimmedAndBlankCommentIsDropped(): void
    {
        $normalized = (new SubmissionNormalizer())->normalize(Submissions::submission([
            'first_name' => ' Léa ',
            'last_name' => 'Martin ',
            'structure_name' => ' Lycée des Quatre-Vallées',
            'comment' => '   ',
        ]));

        self::assertSame(['Léa', 'Martin', 'Lycée des Quatre-Vallées', null], [
            $normalized->firstName,
            $normalized->lastName,
            $normalized->structureName,
            $normalized->comment,
        ]);
    }

    /**
     * @param array<string, mixed> $submission
     */
    #[DataProvider('unreadableSubmissions')]
    public function testSubmissionThatCannotBePlacedIsUnreadable(array $submission): void
    {
        $this->expectException(UnreadableSubmission::class);

        (new SubmissionNormalizer())->normalize($submission);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableSubmissions(): iterable
    {
        yield 'no id' => [Submissions::submission(submission: ['id' => null])];
        yield 'unknown status' => [Submissions::submission(submission: ['status' => 'deleted'])];
        yield 'no session' => [Submissions::submission(['session' => null])];
        yield 'session without id' => [Submissions::submission(['session' => ['label' => 'Belley']])];
        yield 'unreadable timestamp' => [Submissions::submission(submission: ['updated_at' => 'yesterday-ish'])];
    }
}
