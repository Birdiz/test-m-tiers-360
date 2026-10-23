<?php

declare(strict_types=1);

namespace App\Import;

enum AnomalyCode: string
{
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Implausible = 'implausible';
    case Unknown = 'unknown';
}
