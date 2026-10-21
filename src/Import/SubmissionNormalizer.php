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
        $answers = \is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
        $externalId = self::optionalText($submission['id'] ?? null) ?? throw new UnreadableSubmission('no id');
        $status = RegistrationStatus::tryFrom(\is_string($submission['status'] ?? null) ? $submission['status'] : '')
            ?? throw new UnreadableSubmission(\sprintf('%s: unknown status', $externalId));
        $createdAt = self::timestamp($submission['created_at'] ?? null) ?? throw new UnreadableSubmission(\sprintf('%s: unreadable created_at', $externalId));
        $updatedAt = self::timestamp($submission['updated_at'] ?? null) ?? throw new UnreadableSubmission(\sprintf('%s: unreadable updated_at', $externalId));
        $session = self::session($answers['session'] ?? null) ?? throw new UnreadableSubmission(\sprintf('%s: no readable session', $externalId));
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

        return $name;
    }

    private static function optionalText(mixed $raw): ?string
    {
        return \is_string($raw) && '' !== trim($raw) ? trim($raw) : null;
    }

    private static function session(mixed $raw): ?SessionSnapshot
    {
        if (!\is_array($raw) || null === $id = self::optionalText($raw['id'] ?? null)) {
            return null;
        }

        $date = \is_string($raw['date'] ?? null) ? self::strictDate($raw['date'], 'Y-m-d') : null;
        if (null === $date || !\is_int($raw['capacity'] ?? null)) {
            return null;
        }

        return new SessionSnapshot(
            externalId: $id,
            label: self::optionalText($raw['label'] ?? null) ?? $id,
            date: $date,
            location: self::optionalText($raw['location'] ?? null) ?? '',
            capacity: $raw['capacity'],
        );
    }

    private static function timestamp(mixed $raw): ?\DateTimeImmutable
    {
        if (!\is_string($raw)) {
            return null;
        }

        $timestamp = \DateTimeImmutable::createFromFormat(\DATE_ATOM, str_replace('Z', '+00:00', $raw));

        return false === $timestamp ? null : $timestamp;
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

        return $name ?? '';
    }
}
