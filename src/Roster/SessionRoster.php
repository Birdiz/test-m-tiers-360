<?php

declare(strict_types=1);

namespace App\Roster;

use App\Entity\Registration;
use App\Repository\ImportRunRepository;
use App\Repository\RegistrationRepository;
use App\Repository\SessionRepository;

final readonly class SessionRoster
{
    public function __construct(
        private SessionRepository $sessions,
        private RegistrationRepository $registrations,
        private ImportRunRepository $runs,
    ) {
    }

    public function forSession(string $sessionExternalId): ?Roster
    {
        $session = $this->sessions->findOneBy(['externalId' => $sessionExternalId]);
        if (null === $session) {
            return null;
        }

        $registrations = $this->registrations->findBySession($session);

        return Roster::of(
            $session,
            $registrations,
            $this->registrations->findInOtherSessionsByEmails($session, self::distinctEmails($registrations)),
            $this->runs->lastFinished(),
            $this->runs->lastCompleted(),
        );
    }

    /**
     * @param list<Registration> $registrations
     *
     * @return list<string>
     */
    private static function distinctEmails(array $registrations): array
    {
        $emails = [];
        foreach ($registrations as $registration) {
            $email = $registration->getEmail();
            if (null !== $email && !\in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }
}
