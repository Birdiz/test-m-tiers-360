<?php

namespace App\Import;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Session;
use App\Entity\StructureType;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns one submission of the form API into our vocabulary. Never rejects a dirty answer:
 * the field is left empty and an anomaly says why (ADR 0003).
 *
 * Each value is checked against the constraints of the entity property it ends up in, which mirror the
 * database columns: what it returns can be stored (ADR 0014).
 */
final readonly class SubmissionNormalizer
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param mixed $submission one entry of a page's data, as decoded: nothing guarantees it is an object
     */
    public function normalize(mixed $submission): NormalizedSubmission
    {
        if (!\is_array($submission)) {
            throw new UnreadableSubmission('not an object');
        }

        $answers = \is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
        $externalId = self::trimToNull($submission['id'] ?? null) ?? throw new UnreadableSubmission('no id');
        if (!$this->fits(Registration::class, 'externalId', $externalId)) {
            throw new UnreadableSubmission('id our database cannot hold', $externalId);
        }
        $status = RegistrationStatus::tryFrom(\is_string($submission['status'] ?? null) ? $submission['status'] : '')
            ?? throw new UnreadableSubmission('unknown status', $externalId);
        $createdAt = $this->keepStorableTimestamp('sourceCreatedAt', self::parseTimestamp($submission['created_at'] ?? null)) ?? throw new UnreadableSubmission('unreadable created_at', $externalId);
        $updatedAt = $this->keepStorableTimestamp('sourceUpdatedAt', self::parseTimestamp($submission['updated_at'] ?? null)) ?? throw new UnreadableSubmission('unreadable updated_at', $externalId);
        $session = $this->parseStorableSession($answers['session'] ?? null) ?? throw new UnreadableSubmission('no readable session', $externalId);
        $anomalies = [];

        return new NormalizedSubmission(
            externalId: $externalId,
            status: $status,
            sourceCreatedAt: $createdAt,
            sourceUpdatedAt: $updatedAt,
            firstName: $this->keepIfStorable('first_name', 'firstName', self::normalizeName('first_name', $answers['first_name'] ?? null, $anomalies), $anomalies) ?? '',
            lastName: $this->keepIfStorable('last_name', 'lastName', self::normalizeName('last_name', $answers['last_name'] ?? null, $anomalies), $anomalies) ?? '',
            email: $this->keepIfStorable('email', 'email', self::normalizeEmail($answers['email'] ?? null, $anomalies), $anomalies),
            phone: $this->keepIfStorable('phone', 'phone', self::normalizePhone($answers['phone'] ?? null, $anomalies), $anomalies),
            birthDate: self::normalizeBirthDate($answers['birth_date'] ?? null, $createdAt, $anomalies),
            structureType: self::normalizeStructureType($answers['structure_type'] ?? null, $anomalies),
            structureName: $this->keepIfStorable('structure_name', 'structureName', self::normalizeStructureName($answers['structure_name'] ?? null, $anomalies), $anomalies),
            comment: self::trimToNull($answers['comment'] ?? null),
            session: $session,
            anomalies: $anomalies,
        );
    }

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function normalizeEmail(mixed $raw, array &$anomalies): ?string
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
    private static function normalizePhone(mixed $raw, array &$anomalies): ?string
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
    private static function normalizeBirthDate(mixed $raw, \DateTimeImmutable $submittedAt, array &$anomalies): ?\DateTimeImmutable
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            $anomalies[] = new Anomaly('birth_date', AnomalyCode::Missing, null);

            return null;
        }

        $date = self::parseStrictDate(trim($raw), 'Y-m-d') ?? self::parseStrictDate(trim($raw), 'd/m/Y');
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

    private static function parseStrictDate(string $value, string $format): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));

        // A round trip rejects overflows such as 2009-02-30, which PHP silently turns into March 2nd.
        return false !== $date && $date->format($format) === $value ? $date : null;
    }

    /**
     * @param list<Anomaly> $anomalies
     */
    private static function normalizeStructureType(mixed $raw, array &$anomalies): StructureType
    {
        $value = self::trimToNull($raw);
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
    private static function normalizeStructureName(mixed $raw, array &$anomalies): ?string
    {
        $name = self::trimToNull($raw);
        if (null === $name) {
            $anomalies[] = new Anomaly('structure_name', AnomalyCode::Missing, null);
        }

        return $name;
    }

    /**
     * Drops NUL bytes: Postgres cannot store them, and the driver silently cuts a value at the first one.
     */
    private static function trimToNull(mixed $raw): ?string
    {
        $text = \is_string($raw) ? trim(str_replace("\0", '', $raw)) : '';

        return '' !== $text ? $text : null;
    }

    /**
     * Whether the database can hold this value in that entity property (ADR 0014).
     *
     * @param class-string $entity
     */
    private function fits(string $entity, string $property, mixed $value): bool
    {
        return 0 === \count($this->validator->validatePropertyValue($entity, $property, $value));
    }

    /**
     * An answer the database cannot hold is left empty and flagged, like any other dirty answer: cut, it would
     * look valid. Answers only carry length constraints.
     *
     * @param list<Anomaly> $anomalies
     */
    private function keepIfStorable(string $field, string $property, ?string $value, array &$anomalies): ?string
    {
        if (null === $value || $this->fits(Registration::class, $property, $value)) {
            return $value;
        }

        $anomalies[] = new Anomaly($field, AnomalyCode::TooLong, $value);

        return null;
    }

    private function keepStorableTimestamp(string $property, ?\DateTimeImmutable $timestamp): ?\DateTimeImmutable
    {
        return null !== $timestamp && $this->fits(Registration::class, $property, $timestamp) ? $timestamp : null;
    }

    /**
     * A session the database cannot hold cannot be tied to anything. Its label and location are cut instead:
     * they describe no one, and a session has no anomaly to keep the rest.
     */
    private function parseStorableSession(mixed $raw): ?SessionSnapshot
    {
        $session = self::parseSession($raw);
        if (null === $session
            || !$this->fits(Session::class, 'externalId', $session->externalId)
            || !$this->fits(Session::class, 'date', $session->date)
            || !$this->fits(Session::class, 'capacity', $session->capacity)) {
            return null;
        }

        return new SessionSnapshot(
            externalId: $session->externalId,
            label: mb_substr($session->label, 0, Session::LABEL_LENGTH),
            date: $session->date,
            location: mb_substr($session->location, 0, Session::LOCATION_LENGTH),
            capacity: $session->capacity,
        );
    }

    private static function parseSession(mixed $raw): ?SessionSnapshot
    {
        if (!\is_array($raw) || null === $id = self::trimToNull($raw['id'] ?? null)) {
            return null;
        }

        $date = \is_string($raw['date'] ?? null) ? self::parseStrictDate($raw['date'], 'Y-m-d') : null;
        if (null === $date || !\is_int($raw['capacity'] ?? null)) {
            return null;
        }

        return new SessionSnapshot(
            externalId: $id,
            label: self::trimToNull($raw['label'] ?? null) ?? $id,
            date: $date,
            location: self::trimToNull($raw['location'] ?? null) ?? '',
            capacity: $raw['capacity'],
        );
    }

    private static function parseTimestamp(mixed $raw): ?\DateTimeImmutable
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
    private static function normalizeName(string $field, mixed $raw, array &$anomalies): string
    {
        $name = self::trimToNull($raw);
        if (null === $name) {
            $anomalies[] = new Anomaly($field, AnomalyCode::Missing, null);
        }

        return $name ?? '';
    }
}
