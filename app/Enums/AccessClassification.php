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
            self::BorrowerOnly => 'Borrower',
            self::SpmuHead => 'SPMU Head',
            self::SpmuOfficer => 'SPMU Action Officer',
            self::GsuHead => 'GSU Head',
            self::VpafHead => 'VPAF Head',
            self::IctuMaintainer => 'ICTU Maintainer',
        };
    }

    /** @return list<UserRole> */
    public function roles(): array
    {
        return match ($this) {
            self::BorrowerOnly => [UserRole::Borrower],
            self::SpmuHead => [UserRole::Spmu],
            self::SpmuOfficer => [UserRole::Spmu],
            self::GsuHead => [UserRole::Gsu],
            self::VpafHead => [UserRole::Vpaf],
            self::IctuMaintainer => [UserRole::Ictu],
        };
    }

    public function primaryWorkspace(): UserRole
    {
        return $this->roles()[0];
    }

    /** @return list<string> */
    public function workspaces(): array
    {
        return array_map(fn (UserRole $role) => $role->value, $this->roles());
    }

    public function mayBorrow(): bool
    {
        return $this === self::BorrowerOnly;
    }
}
