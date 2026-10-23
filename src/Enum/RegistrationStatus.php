<?php

declare(strict_types=1);

namespace App\Enum;

enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
}
