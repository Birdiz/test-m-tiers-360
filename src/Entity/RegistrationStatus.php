<?php

declare(strict_types=1);

namespace App\Entity;

enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
}
