<?php

declare(strict_types=1);

namespace App\Roster;

use App\Entity\Registration;
use App\Repository\ImportRunRepository;
use App\Repository\RegistrationRepository;
use App\Repository\SessionRepository;

final class SessionRoster
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly RegistrationRepository $registrations,
        private readonly ImportRunRepository $runs,
    ) {
    }

    public function forSession(string $sessionExternalId): ?Roster
    {
        $session = $this->sessions->findOneBy(['externalId' => $sessionExternalId]);
        if (null === $session) {
            return null;
        }

        $registrations = $this->registrations->findBySession($session);
        $emails = array_values(array_unique(array_filter(array_map(static fn (Registration $r): ?string => $r->getEmail(), $registrations))));

        return Roster::of(
            $session,
            $registrations,
            $this->registrations->findInOtherSessionsByEmails($session, $emails),
            $this->runs->lastFinished(),
            $this->runs->lastCompleted(),
        );
    }
}
