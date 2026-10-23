<?php

declare(strict_types=1);

namespace App\Roster;

/**
 * Why the ops team should look at an active registration.
 */
enum ReviewReason: string
{
    /** Another active registration of the session looks like the same person. */
    case ProbableDuplicate = 'probable_duplicate';
    /** The same person is also active on another session of the operation. */
    case OtherSession = 'other_session';
    /** Neither a valid email nor a phone: nobody can send a reminder or a cancellation. */
    case Unreachable = 'unreachable';
    /** A field the import could not understand, to fix in the form tool. */
    case Anomaly = 'anomaly';
    /** A free comment, often a need for the day (accessibility, glasses...). */
    case Comment = 'comment';
}
