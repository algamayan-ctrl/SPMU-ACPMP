<?php

namespace Database\Seeders;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Models\OrganizationalUnit;
use App\Models\Role;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'SPMU-Demo-2026!';

    public function run(): void
    {
        if (! config('app.seed_demo_users')) {
            return;
        }
        foreach ($this->accounts() as [$classification, $email, $employeeNo, $name, $unitCode, $employment]) {
            $unit = OrganizationalUnit::query()->where('unit_code', $unitCode)->firstOrFail();
            $user = User::query()->updateOrCreate(['email' => $email], [
                'organizational_unit_id' => $unit->id,
                'employee_no' => $employeeNo,
                'full_name' => $name,
                'designation' => $classification->label(),
                'employment_type' => $employment,
                'mobile_no' => '09170000000',
                'notification_preferences' => ['system' => true, 'email' => true, 'sms' => true],
                'account_status' => AccountStatus::Active,
                'access_classification' => $classification,
                'email_verified_at' => now(),
                'password' => Hash::make(self::PASSWORD),
            ]);
            foreach ($classification->roles() as $roleCode) {
                $role = Role::query()->where('role_code', $roleCode->value)->firstOrFail();
                $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
            }
            $this->signature($user, $classification->value);
        }
    }

    private function signature(User $user, string $role): void
    {
        if ($user->currentSignature()->exists()) {
            return;
        }
        $initials = collect(explode(' ', $user->full_name))->map(fn ($part) => mb_substr($part, 0, 1))->join('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="360" height="110"><rect width="100%" height="100%" fill="white"/><text x="20" y="58" font-family="cursive" font-size="34" fill="#0b2545">'.e($initials).' / '.$role.'</text><text x="20" y="90" font-family="Arial" font-size="12" fill="#64748b">Local demonstration e-signature</text></svg>';
        $path = 'demo-signatures/user-'.$user->id.'.svg';
        Storage::disk('local')->put($path, $svg);
        $file = StoredFile::query()->create([
            'uploaded_by_user_id' => $user->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'demo-signature-'.$user->id.'.svg',
            'mime_type' => 'image/svg+xml',
            'byte_size' => strlen($svg),
            'sha256' => hash('sha256', $svg),
            'classification' => 'PROFILE_SIGNATURE',
        ]);
        UserSignature::query()->create(['user_id' => $user->id, 'stored_file_id' => $file->id, 'effective_from' => now(), 'status' => 'ACTIVE']);
    }

    private function accounts(): array
    {
        return [
            [AccessClassification::BorrowerOnly, 'borrower@spmu.test', 'DEMO-BORROWER', 'Borrower Demo', 'CSPC', EmploymentType::Faculty],
            [AccessClassification::SpmuOfficer, 'spmu@spmu.test', 'DEMO-SPMU', 'SPMU Action Officer Demo', 'SPMU', EmploymentType::Staff],
            [AccessClassification::SpmuHead, 'spmu-head@spmu.test', 'DEMO-SPMU-HEAD', 'SPMU Head Demo', 'SPMU', EmploymentType::Employee],
            [AccessClassification::GsuHead, 'gsu@spmu.test', 'DEMO-GSU', 'GSU Head Demo', 'GSU', EmploymentType::Staff],
            [AccessClassification::VpafHead, 'vpaf@spmu.test', 'DEMO-VPAF', 'VPAF Head Demo', 'VPAF', EmploymentType::Employee],
            [AccessClassification::IctuMaintainer, 'ictu@spmu.test', 'DEMO-ICTU', 'ICTU Maintainer Demo', 'ICTU', EmploymentType::Staff],
        ];
    }
}
