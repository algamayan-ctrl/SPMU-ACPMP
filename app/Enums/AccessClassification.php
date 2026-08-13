<?php

namespace App\Enums;

enum AccessClassification: string
{
    case BorrowerOnly = 'BORROWER_ONLY';
    case SpmuHead = 'SPMU_HEAD';
    case SpmuOfficer = 'SPMU_OFFICER';
    case GsuHead = 'GSU_HEAD';
    case VpafHead = 'VPAF_HEAD';
    case IctuMaintainer = 'ICTU_MAINTAINER';

    public function label(): string
    {
        return match ($this) {
            self::BorrowerOnly => 'Borrower only',
            self::SpmuHead => 'SPMU Head - approver, not a borrower',
            self::SpmuOfficer => 'SPMU Action Officer + Borrower',
            self::GsuHead => 'GSU Head - approver, not a borrower',
            self::VpafHead => 'VPAF Head - approver, not a borrower',
            self::IctuMaintainer => 'ICTU Maintainer + Borrower',
        };
    }

    /** @return list<UserRole> */
    public function roles(): array
    {
        return match ($this) {
            self::BorrowerOnly => [UserRole::Borrower],
            self::SpmuHead => [UserRole::Spmu],
            self::SpmuOfficer => [UserRole::Borrower, UserRole::Spmu],
            self::GsuHead => [UserRole::Gsu],
            self::VpafHead => [UserRole::Vpaf],
            self::IctuMaintainer => [UserRole::Borrower, UserRole::Ictu],
        };
    }

    /** @return list<string> */
    public function workspaces(): array
    {
        return array_map(fn (UserRole $role) => $role->value, $this->roles());
    }

    public function mayBorrow(): bool
    {
        return in_array(UserRole::Borrower, $this->roles(), true);
    }
}
