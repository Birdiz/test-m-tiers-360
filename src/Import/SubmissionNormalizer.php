<?php

namespace App\Import;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Session;
use App\Entity\StructureType;

/**
 * Turns one submission of the form API into our vocabulary. Never rejects a dirty answer:
 * the field is left empty and an anomaly says why (ADR 0003).
 *
 * The only gate before the database: what it returns fits our columns (ADR 0014).
 */
final class SubmissionNormalizer
{
    /**
     * @param array<string, mixed> $submission
     */
    public function normalize(array $submission): NormalizedSubmission
    {
        $answers = \is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
        $externalId = self::optionalText($submission['id'] ?? null) ?? throw new UnreadableSubmission('no id');
        if (mb_strlen($externalId) > Registration::EXTERNAL_ID_LENGTH) {
            throw new UnreadableSubmission(\sprintf('id longer than %d characters', Registration::EXTERNAL_ID_LENGTH), $externalId);
        }
        $status = RegistrationStatus::tryFrom(\is_string($submission['status'] ?? null) ? $submission['status'] : '')
            ?? throw new UnreadableSubmission('unknown status', $externalId);
        $createdAt = self::timestamp($submission['created_at'] ?? null) ?? throw new UnreadableSubmission('unreadable created_at', $externalId);
        $updatedAt = self::timestamp($submission['updated_at'] ?? null) ?? throw new UnreadableSubmission('unreadable updated_at', $externalId);
        $session = self::session($answers['session'] ?? null) ?? throw new UnreadableSubmission('no readable session', $externalId);
        $anomalies = [];

        return new NormalizedSubmission(
            externalId: $externalId,
            status: $status,
            sourceCreatedAt: $createdAt,
            sourceUpdatedAt: $updatedAt,
            firstName: self::name('first_name', $answers['first_name'] ?? null, $anomalies),
            lastName: self::name('last_name', $answers['last_name'] ?? null, $anomalies),
            email: self::email($answers['email'] ?? null, $anomalies),
            phone: self::phone($answers['phone'] ?? null, $anomalies),
            birthDate: self::birthDate($answers['birth_date'] ?? null, $createdAt, $anomalies),
            structureType: self::structureType($answers['structure_type'] ?? null, $anomalies),
            structureName: self::structureName($answers['structure_name'] ?? null, $anomalies),
            comment: self::optionalText($answers['comment'] ?? null),
            session: $session,
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

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function structureType(mixed $raw, array &$anomalies): StructureType
    {
        $value = self::optionalText($raw);
        if (null === $value) {
            $anomalies[] = new Anomaly('structure_type', AnomalyCode::Missing, null);

            return StructureType::Other;
        }

        $type = StructureType::tryFrom($value);
        if (null === $type || StructureType::Other === $type) {
            $anomalies[] = new Anomaly('structure_type', AnomalyCode::Unknown, $value);

            return StructureType::Other;
        }

        return $type;
    }

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function structureName(mixed $raw, array &$anomalies): ?string
    {
        $name = self::optionalText($raw);
        if (null === $name) {
            $anomalies[] = new Anomaly('structure_name', AnomalyCode::Missing, null);
        }

        return self::fitting('structure_name', $name, Registration::STRUCTURE_NAME_LENGTH, $anomalies);
    }

    /**
     * Drops NUL bytes: Postgres cannot store them, and the driver silently cuts a value at the first one.
     */
    private static function optionalText(mixed $raw): ?string
    {
        $text = \is_string($raw) ? trim(str_replace("\0", '', $raw)) : '';

        return '' !== $text ? $text : null;
    }

    /**
     * An answer longer than its column is left empty and flagged, like any other dirty answer: cut, it would
     * look valid.
     *
     * @param list<Anomaly> $anomalies
     */
    private static function fitting(string $field, ?string $value, int $length, array &$anomalies): ?string
    {
        if (null === $value || mb_strlen($value) <= $length) {
            return $value;
        }

        $anomalies[] = new Anomaly($field, AnomalyCode::TooLong, $value);

        return null;
    }

    /**
     * Postgres has no year 0 nor any year before it.
     */
    private static function storable(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        return null !== $date && (int) $date->format('Y') >= 1 ? $date : null;
    }

    private static function session(mixed $raw): ?SessionSnapshot
    {
        if (!\is_array($raw) || null === $id = self::optionalText($raw['id'] ?? null)) {
            return null;
        }

        $date = self::storable(\is_string($raw['date'] ?? null) ? self::strictDate($raw['date'], 'Y-m-d') : null);
        $capacity = $raw['capacity'] ?? null;
        if (mb_strlen($id) > Session::EXTERNAL_ID_LENGTH || null === $date || !\is_int($capacity) || $capacity > Session::MAX_CAPACITY) {
            return null;
        }

        // Descriptive, carries no personal data, and has no anomaly to hold the rest: cut to the column.
        return new SessionSnapshot(
            externalId: $id,
            label: mb_substr(self::optionalText($raw['label'] ?? null) ?? $id, 0, Session::LABEL_LENGTH),
            date: $date,
            location: mb_substr(self::optionalText($raw['location'] ?? null) ?? '', 0, Session::LOCATION_LENGTH),
            capacity: $capacity,
        );
    }

    private static function timestamp(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw)) {
            return null;
        }

        $timestamp = \DateTimeImmutable::createFromFormat(\DATE_ATOM, str_replace('Z', '+00:00', $raw));

        return self::storable(false === $timestamp ? null : $timestamp);
    }

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function name(string $field, mixed $raw, array &$anomalies): string
    {
        $name = self::optionalText($raw);
        if (null === $name) {
            $anomalies[] = new Anomaly($field, AnomalyCode::Missing, null);
        }

        return self::fitting($field, $name, Registration::NAME_LENGTH, $anomalies) ?? '';
    }
}
