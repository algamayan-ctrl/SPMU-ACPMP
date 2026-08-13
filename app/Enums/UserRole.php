<?php

namespace App\Enums;

enum UserRole: string
{
    case Borrower = 'BORROWER';
    case Spmu = 'SPMU';
    case Gsu = 'GSU';
    case Vpaf = 'VPAF';
    case Ictu = 'ICTU';

    public function label(): string
    {
        return match ($this) {
            self::Borrower => 'Borrower',
            self::Spmu => 'Supply and Property Management Unit',
            self::Gsu => 'General Services Unit',
            self::Vpaf => 'Vice President for Administration and Finance',
            self::Ictu => 'Information and Communications Technology Unit',
        };
    }
}
