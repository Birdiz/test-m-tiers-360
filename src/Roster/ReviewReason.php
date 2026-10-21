<?php

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
}
