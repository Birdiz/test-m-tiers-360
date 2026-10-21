<?php

namespace App\Entity;

enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Cancelled = 'cancelled';
}
