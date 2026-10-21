<?php

namespace App\Import;

/**
 * A submission that cannot be tied to a registration or a session. Unlike a dirty answer, it is not imported.
 */
final class UnreadableSubmission extends \RuntimeException
{
}
