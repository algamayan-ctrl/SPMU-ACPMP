<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Suspended = 'SUSPENDED';
}
