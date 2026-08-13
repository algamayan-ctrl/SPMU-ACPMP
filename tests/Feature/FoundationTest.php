<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('SPMU-ACPMP')
            ->assertSee('SPMU')
            ->assertSee('GSU')
            ->assertSee('VPAF');
    }

    public function test_core_schema_and_reference_data_are_ready(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['users', 'roles', 'inventory_items', 'borrowing_requests', 'approval_steps', 'allocations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected the {$table} table to exist.");
        }

        $this->assertSame(count(UserRole::cases()), Role::query()->count());
        $this->assertSame('6.000', InventoryItem::query()->where('unique_description', 'Barricade')->value('total_quantity'));
        $this->assertTrue(InventoryItem::query()->where('unique_description', 'Barricade')->value('provisional'));
    }
}
