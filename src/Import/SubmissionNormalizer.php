<?php

namespace App\Import;

use App\Entity\RegistrationStatus;
use App\Entity\StructureType;

/**
 * Turns one submission of the form API into our vocabulary. Never rejects a dirty answer:
 * the field is left empty and an anomaly says why (ADR 0003).
 */
final class SubmissionNormalizer
{
    /**
     * @param array<string, mixed> $submission
     */
    public function normalize(array $submission): NormalizedSubmission
    {
        $answers = $submission['answers'];
        $session = $answers['session'];
        $anomalies = [];
        $createdAt = new \DateTimeImmutable($submission['created_at']);

        return new NormalizedSubmission(
            externalId: $submission['id'],
            status: RegistrationStatus::from($submission['status']),
            sourceCreatedAt: $createdAt,
            sourceUpdatedAt: new \DateTimeImmutable($submission['updated_at']),
            firstName: $answers['first_name'],
            lastName: $answers['last_name'],
            email: self::email($answers['email'] ?? null, $anomalies),
            phone: self::phone($answers['phone'] ?? null, $anomalies),
            birthDate: self::birthDate($answers['birth_date'] ?? null, $createdAt, $anomalies),
            structureType: StructureType::from($answers['structure_type']),
            structureName: $answers['structure_name'],
            comment: $answers['comment'],
            session: new SessionSnapshot(
                externalId: $session['id'],
                label: $session['label'],
                date: new \DateTimeImmutable($session['date']),
                location: $session['location'],
                capacity: $session['capacity'],
            ),
            anomalies: $anomalies,
        );
    }

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function email(mixed $raw, array &$anomalies): ?string
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            $anomalies[] = new Anomaly('email', AnomalyCode::Missing, null);

            return null;
        }

        $email = mb_strtolower(trim($raw));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $anomalies[] = new Anomaly('email', AnomalyCode::Invalid, $raw);

            return null;
        }

        return $email;
    }

    /**
     * Optional field: absent is fine, unreadable is an anomaly. Recognizes the French formats seen in the source.
     *
     * @param list<Anomaly> $anomalies
     */
    private static function phone(mixed $raw, array &$anomalies): ?string
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $digits = (string) preg_replace('/[\s.\-]/', '', $raw);
        if (1 === preg_match('/^(?:\+33|0)([1-9]\d{8})$/', $digits, $matches)) {
            return '+33'.$matches[1];
        }

        $anomalies[] = new Anomaly('phone', AnomalyCode::Invalid, $raw);

        return null;
    }

    /**
     * Accepts `YYYY-MM-DD` and the French `DD/MM/YYYY`. A date after the submission or more than
     * 100 years before it is implausible.
     *
     * @param list<Anomaly> $anomalies
     */
    private static function birthDate(mixed $raw, \DateTimeImmutable $submittedAt, array &$anomalies): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            $anomalies[] = new Anomaly('birth_date', AnomalyCode::Missing, null);

            return null;
        }

        $date = self::strictDate(trim($raw), 'Y-m-d') ?? self::strictDate(trim($raw), 'd/m/Y');
        if (null === $date) {
            $anomalies[] = new Anomaly('birth_date', AnomalyCode::Invalid, $raw);

            return null;
        }

        if ($date > $submittedAt || $date < $submittedAt->modify('-100 years')) {
            $anomalies[] = new Anomaly('birth_date', AnomalyCode::Implausible, $raw);

            return null;
        }

        return $date;
    }

    private static function strictDate(string $value, string $format): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));

        // A round trip rejects overflows such as 2009-02-30, which PHP silently turns into March 2nd.
        return false !== $date && $date->format($format) === $value ? $date : null;
    }
}
