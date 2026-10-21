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

        return new NormalizedSubmission(
            externalId: $submission['id'],
            status: RegistrationStatus::from($submission['status']),
            sourceCreatedAt: new \DateTimeImmutable($submission['created_at']),
            sourceUpdatedAt: new \DateTimeImmutable($submission['updated_at']),
            firstName: $answers['first_name'],
            lastName: $answers['last_name'],
            email: self::email($answers['email'] ?? null, $anomalies),
            phone: '+33'.substr(preg_replace('/\D/', '', $answers['phone']), 1),
            birthDate: new \DateTimeImmutable($answers['birth_date']),
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
}
