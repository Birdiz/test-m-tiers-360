<?php

namespace App\Roster;

use App\Repository\RegistrationRepository;
use App\Repository\SessionRepository;

final class SessionRoster
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly RegistrationRepository $registrations,
    ) {
    }

    public function forSession(string $sessionExternalId): ?Roster
    {
        $session = $this->sessions->findOneBy(['externalId' => $sessionExternalId]);

        return null === $session ? null : Roster::of($session, $this->registrations->findBySession($session));
    }
}
