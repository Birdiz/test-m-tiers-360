<?php

namespace App\Import;

enum AnomalyCode: string
{
    case Missing = 'missing';
    case Invalid = 'invalid';
    case Implausible = 'implausible';
    case Unknown = 'unknown';
}
