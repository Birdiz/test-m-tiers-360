<?php

namespace App\Enum;

enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
}
