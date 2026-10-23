<?php

namespace App\Import;

enum AnomalyCode: string
{
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Implausible = 'implausible';
    case Unknown = 'unknown';
    /** Longer than our column: the field is left empty rather than cut (ADR 0014). */
    case TooLong = 'too_long';
}
