<?php

namespace App\Enums;

enum ApprovalStage: string
{
    case Spmu = 'SPMU';
    case Gsu = 'GSU';
    case Vpaf = 'VPAF';

    public function sequence(): int
    {
        return match ($this) {
            self::Spmu => 1,
            self::Gsu => 2,
            self::Vpaf => 3,
        };
    }
}
