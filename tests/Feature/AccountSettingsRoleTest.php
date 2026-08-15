<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\SignatureService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountSettingsRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shared_account_menu_and_settings_render_for_every_normal_classification(): void
    {
        foreach (AccessClassification::cases() as $classification) {
            $user = $this->classificationUser($classification);

            $dashboard = $this->actingAs($user)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('data-account-menu', false)
                ->assertSee('data-account-menu-toggle', false)
                ->assertSee($user->full_name)
                ->assertSee($classification->label())
                ->assertSee('Account Settings')
                ->assertSee('Log out')
                ->assertDontSee('View profile')
                ->assertDontSee('Sign out');

            foreach (['Borrower Portal', 'SPMU Operations', 'GSU Approval', 'VPAF Approval', 'ICTU Administration', 'Current Workspace', 'You are using'] as $obsoleteLabel) {
                $dashboard->assertDontSee($obsoleteLabel);
            }

            $settings = $this->actingAs($user)->get(route('profile.show'))
                ->assertOk()
                ->assertSee('Account Settings')
                ->assertSee('Contact Information')
                ->assertSee('E-Signature')
                ->assertSee('Replacing it affects future actions only.');

            if ($classification === AccessClassification::BorrowerOnly) {
                $settings->assertSee('Borrower Number')->assertDontSee('Employee Number');
            } else {
                $settings->assertSee('Employee Number')->assertDontSee('Borrower Number');
            }
        }
    }

    public function test_borrower_can_update_only_the_explicit_borrower_identity_fields(): void
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $academicUnit = OrganizationalUnit::query()->create([
            'unit_code' => 'CCS',
            'unit_name' => 'College of Computer Studies',
            'unit_type' => 'ACADEMIC_UNIT',
            'active' => true,
        ]);

        $this->actingAs($borrower)->put(route('profile.update'), $this->profilePayload($borrower) + [
            'employee_no' => 'BORROWER-2026-001',
            'organizational_unit_id' => $academicUnit->id,
            'access_classification' => AccessClassification::IctuMaintainer->value,
            'account_status' => AccountStatus::Disabled->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $borrower->refresh();
        $this->assertSame('BORROWER-2026-001', $borrower->employee_no);
        $this->assertSame($academicUnit->id, $borrower->organizational_unit_id);
        $this->assertSame(AccessClassification::BorrowerOnly, $borrower->access_classification);
        $this->assertSame(AccountStatus::Active, $borrower->account_status);

        $authorityUnit = OrganizationalUnit::query()->where('unit_code', 'GSU')->firstOrFail();
        $this->actingAs($borrower)->put(route('profile.update'), $this->profilePayload($borrower) + [
            'employee_no' => $borrower->employee_no,
            'organizational_unit_id' => $authorityUnit->id,
        ])->assertSessionHasErrors('organizational_unit_id');
        $this->assertSame($academicUnit->id, $borrower->fresh()->organizational_unit_id);
    }

    public function test_staff_cannot_self_modify_authority_sensitive_account_fields(): void
    {
        foreach (array_filter(AccessClassification::cases(), fn ($classification) => $classification !== AccessClassification::BorrowerOnly) as $classification) {
            $user = $this->classificationUser($classification);
            $before = $user->only(['employee_no', 'organizational_unit_id', 'access_classification', 'account_status', 'email', 'employment_type']);
            $differentUnit = OrganizationalUnit::query()->whereKeyNot($user->organizational_unit_id)->firstOrFail();

            $this->actingAs($user)->put(route('profile.update'), $this->profilePayload($user) + [
                'employee_no' => 'SELF-CHANGED-'.$user->id,
                'organizational_unit_id' => $differentUnit->id,
                'access_classification' => AccessClassification::BorrowerOnly->value,
                'account_status' => AccountStatus::Disabled->value,
                'email' => 'changed-'.$user->id.'@example.test',
                'employment_type' => 'FACULTY',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame($before, $user->fresh()->only(array_keys($before)));
        }
    }

    public function test_replacing_profile_signature_does_not_change_an_existing_snapshot(): void
    {
        Storage::fake('local');
        $user = $this->classificationUser(AccessClassification::SpmuOfficer);
        $signature = $user->currentSignature()->with('file')->firstOrFail();
        $originalBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Storage::disk('local')->put('profile-signatures/original.png', $originalBytes);
        $signature->file->update([
            'storage_path' => 'profile-signatures/original.png',
            'original_name' => 'original.png',
            'mime_type' => 'image/png',
            'byte_size' => strlen($originalBytes),
            'sha256' => hash('sha256', $originalBytes),
        ]);

        $snapshot = app(SignatureService::class)->snapshot($user, 'ACCOUNT_SETTINGS_TEST', 'SPMU');
        $snapshotBefore = $snapshot->only(['user_signature_id', 'snapshot_file_id', 'signer_name', 'signer_role', 'purpose_code', 'sha256', 'captured_at']);
        $snapshotBytes = Storage::disk('local')->get($snapshot->file->storage_path);
        $replacement = UploadedFile::fake()->createWithContent('replacement.png', $originalBytes);

        $this->actingAs($user)->post(route('profile.signature'), ['signature' => $replacement])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($snapshotBefore, $snapshot->fresh()->only(array_keys($snapshotBefore)));
        $this->assertSame($snapshotBytes, Storage::disk('local')->get($snapshot->fresh()->file->storage_path));
        $this->assertNotSame($signature->id, $user->fresh()->currentSignature->id);
    }

    public function test_logout_route_remains_post_only(): void
    {
        $this->assertSame(['POST'], Route::getRoutes()->getByName('logout')->methods());
    }

    /** @return array<string, mixed> */
    private function profilePayload(User $user): array
    {
        return [
            'full_name' => $user->full_name,
            'designation' => $user->designation,
            'mobile_no' => $user->mobile_no,
            'system_notifications' => '1',
            'email_notifications' => '1',
        ];
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()->where('access_classification', $classification->value)->firstOrFail();
    }
}
