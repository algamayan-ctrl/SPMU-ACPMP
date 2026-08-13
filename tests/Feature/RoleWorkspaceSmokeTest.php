<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleWorkspaceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_borrower_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Borrower, [
            '/dashboard', '/profile', '/notifications', '/inventory', '/calendar', '/requests',
            '/requests/create', '/custody', '/accountability',
        ]);
    }

    public function test_spmu_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Spmu, [
            '/dashboard', '/profile', '/notifications', '/inventory', '/inventory/create', '/calendar',
            '/requests', '/approvals', '/custody', '/accountability', '/reports',
            '/reports/audit', '/reports/notifications', '/administration',
            '/administration/settings',
        ]);
    }

    public function test_gsu_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Gsu, [
            '/dashboard', '/profile', '/notifications', '/inventory', '/calendar', '/requests', '/approvals',
        ]);
    }

    public function test_vpaf_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Vpaf, [
            '/dashboard', '/profile', '/notifications', '/inventory', '/calendar', '/requests', '/approvals', '/reports',
        ]);
    }

    public function test_ictu_workspace_pages_render(): void
    {
        $this->assertPagesRender(UserRole::Ictu, [
            '/dashboard', '/profile', '/notifications',
            '/reports/audit', '/reports/notifications', '/administration',
            '/administration/settings', '/administration/users', '/administration/users/create', '/administration/delegations',
        ]);
    }

    public function test_each_workspace_has_a_focused_role_specific_menu(): void
    {
        $expectations = [
            UserRole::Borrower->value => [
                'see' => ['Available Items', 'My Requests', 'My Borrowings', 'Accountability'],
                'hide' => ['Approval Queue', 'User Accounts'],
            ],
            UserRole::Spmu->value => [
                'see' => ['Approval Queue', 'Inventory', 'Release and Return', 'Reports', 'Configuration'],
                'hide' => ['User Accounts', 'Delegated Approvers'],
            ],
            UserRole::Gsu->value => [
                'see' => ['Approval Queue', 'Request Records', 'Inventory View', 'Borrowing Calendar'],
                'hide' => ['Reports', 'Release and Return'],
            ],
            UserRole::Vpaf->value => [
                'see' => ['Approval Queue', 'Request Records', 'Borrowing Calendar', 'Reports'],
                'hide' => ['Release and Return', 'Configuration'],
            ],
            UserRole::Ictu->value => [
                'see' => ['User Accounts', 'Delegated Approvers', 'System Settings', 'Audit Trail', 'Delivery Records'],
                'hide' => ['Borrowing Calendar', 'Approval Queue', 'Inventory View'],
            ],
        ];

        foreach ($expectations as $roleCode => $labels) {
            $role = UserRole::from($roleCode);
            $user = $this->workspaceUser($role);
            $response = $this->withSession(['active_workspace' => $role->value])
                ->actingAs($user)
                ->get('/dashboard')
                ->assertOk();

            foreach ($labels['see'] as $label) {
                $response->assertSee($label);
            }
            foreach ($labels['hide'] as $label) {
                $response->assertDontSee($label);
            }
        }
    }

    /** @param array<int, string> $paths */
    private function assertPagesRender(UserRole $role, array $paths): void
    {
        $user = $this->workspaceUser($role);

        foreach ($paths as $path) {
            $this->withSession(['active_workspace' => $role->value])->actingAs($user)->get($path)->assertOk();
        }
    }

    private function workspaceUser(UserRole $role): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('role_code', $role->value)->whereNull('user_roles.revoked_at'))
            ->when($role === UserRole::Spmu, fn ($query) => $query->where('access_classification', AccessClassification::SpmuHead->value))
            ->firstOrFail();
    }
}
