<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowerInventoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_borrower_inventory_is_reference_only_and_hides_internal_breakdown(): void
    {
        $borrower = $this->borrower();

        $this->actingAs($borrower)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Inventory')
            ->assertSee('Available Quantity')
            ->assertSee('Availability is for reference only.')
            ->assertSee('Submission of a borrowing request does not reserve an item.')
            ->assertDontSee('Committed quantities')
            ->assertDontSee('On custody');
    }

    public function test_borrower_item_details_remain_read_only_and_do_not_expose_operational_breakdown(): void
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->firstOrFail();

        $this->actingAs($borrower)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertSee('Available for borrowing')
            ->assertSee('Reference only')
            ->assertDontSee('Operational breakdown')
            ->assertDontSee('Inventory master record');
    }

    public function test_pending_request_does_not_reduce_borrower_availability_but_active_allocation_does(): void
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('condition_code', 'SERVICEABLE')
            ->firstOrFail();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-INV-TEST-001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => 'DRAFT',
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Inventory reference test',
            'location' => 'CSPC',
            'needed_from' => now()->addDays(2),
            'return_due_at' => now()->addDays(3),
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 2,
            'use_location' => 'ON_CAMPUS',
        ]);

        $service = app(InventoryService::class);
        $beforeApproval = $service->availability($item, now(), now()->addSecond());

        $this->assertSame((float) $item->total_quantity, (float) $beforeApproval['borrower_available']);
        $this->assertSame(0.0, (float) $beforeApproval['reserved']);

        Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 2,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => now(),
        ]);

        $afterApproval = $service->availability($item, now(), now()->addSecond());

        $this->assertSame(2.0, (float) $afterApproval['reserved']);
        $this->assertSame(
            max(0, (float) $item->total_quantity - 2),
            (float) $afterApproval['borrower_available']
        );
    }

    public function test_spmu_inventory_shows_complete_quantity_status_breakdown(): void
    {
        $spmu = User::query()
            ->where('access_classification', AccessClassification::SpmuHead->value)
            ->firstOrFail();

        $item = InventoryItem::query()->where('active', true)->firstOrFail();

        $this->actingAs($spmu)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertSee('Total Quantity')
            ->assertSee('Available')
            ->assertSee('Reserved')
            ->assertSee('Issued')
            ->assertSee('Unavailable')
            ->assertSee('Condition breakdown');
    }

    private function borrower(): User
    {
        return User::query()
            ->where('access_classification', AccessClassification::BorrowerOnly->value)
            ->firstOrFail();
    }
}
