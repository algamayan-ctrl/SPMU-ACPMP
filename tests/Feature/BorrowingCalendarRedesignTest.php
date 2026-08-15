<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BorrowingCalendarRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_multiple_allocated_items_render_as_one_request_level_calendar_event(): void
    {
        $borrower = $this->borrower();
        $request = $this->allocatedRequest(
            $borrower,
            'BR-CALENDAR-GROUPED',
            CarbonImmutable::parse('2026-08-17 08:00:00'),
            CarbonImmutable::parse('2026-08-18 07:00:00'),
            3,
        );

        $response = $this->calendarAs($borrower, '2026-08')->assertOk();

        $response->assertViewHas('calendarEvents', function (Collection $events) use ($request): bool {
            $event = $events->firstWhere('request_id', $request->id);

            return $events->where('request_id', $request->id)->count() === 1
                && $event['item_count'] === 3
                && $event['items']->count() === 3
                && $event['start_at']->equalTo(CarbonImmutable::parse('2026-08-17 08:00:00'))
                && $event['due_at']->equalTo(CarbonImmutable::parse('2026-08-18 07:00:00'))
                && $event['request_url'] === route('requests.show', $request);
        });
        $response->assertSee('Borrowing starts')
            ->assertSee('Return due')
            ->assertSee('View My Request')
            ->assertSee('3 item types');
    }

    public function test_borrower_sees_only_sanitized_context_for_another_borrowers_reservation(): void
    {
        $borrower = $this->borrower();
        $otherBorrower = User::factory()->create([
            'organizational_unit_id' => $borrower->organizational_unit_id,
            'access_classification' => AccessClassification::BorrowerOnly,
        ]);
        $this->allocatedRequest(
            $otherBorrower,
            'BR-PRIVATE-REQUEST-NUMBER',
            CarbonImmutable::parse('2026-08-20 08:00:00'),
            CarbonImmutable::parse('2026-08-21 17:00:00'),
            2,
            'Private event purpose',
        );

        $this->calendarAs($borrower, '2026-08')
            ->assertOk()
            ->assertSee('Reserved institutional use')
            ->assertSee('details remain private')
            ->assertDontSee('BR-PRIVATE-REQUEST-NUMBER')
            ->assertDontSee('Private event purpose');
    }

    public function test_cross_month_reservation_keeps_start_and_due_markers_in_relevant_month_contexts(): void
    {
        $borrower = $this->borrower();
        $request = $this->allocatedRequest(
            $borrower,
            'BR-CALENDAR-CROSS-MONTH',
            CarbonImmutable::parse('2026-08-31 09:00:00'),
            CarbonImmutable::parse('2026-09-02 17:00:00'),
            1,
        );

        foreach (['2026-08', '2026-09'] as $month) {
            $this->calendarAs($borrower, $month)
                ->assertOk()
                ->assertViewHas('calendarEvents', fn (Collection $events): bool => $events->where('request_id', $request->id)->count() === 1)
                ->assertSee('BR-CALENDAR-CROSS-MONTH')
                ->assertSee('31 Aug 2026, 9:00 AM')
                ->assertSee('02 Sep 2026, 5:00 PM');
        }
    }

    public function test_empty_dynamic_month_keeps_calendar_and_navigation_visible(): void
    {
        $this->calendarAs($this->borrower(), '2040-01')
            ->assertOk()
            ->assertSee('January 2040')
            ->assertSee('No borrowing activity this month.')
            ->assertSee('Previous month', false)
            ->assertSee('Next month', false)
            ->assertViewHas('calendarEvents', fn (Collection $events): bool => $events->isEmpty());
    }

    private function calendarAs(User $user, string $month): TestResponse
    {
        return $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($user)
            ->get(route('calendar.index', ['month' => $month]));
    }

    private function allocatedRequest(
        User $borrower,
        string $requestNo,
        CarbonImmutable $start,
        CarbonImmutable $due,
        int $itemCount,
        string $purpose = 'Calendar grouping demonstration',
    ): BorrowingRequest {
        $request = BorrowingRequest::query()->create([
            'request_no' => $requestNo,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => $purpose,
            'location' => 'CSPC Campus',
            'needed_from' => $start,
            'return_due_at' => $due,
            'created_by_user_id' => $borrower->id,
        ]);

        $items = InventoryItem::query()->with('unit')->where('active', true)->where('borrowable', true)->limit($itemCount)->get();
        $this->assertCount($itemCount, $items);
        foreach ($items as $item) {
            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description,
                'unit_snapshot' => $item->unit->unit_name,
                'requested_quantity' => 1,
                'approved_quantity' => 1,
                'use_location' => 'ON_CAMPUS',
            ]);
            Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $start,
                'period_end' => $due,
                'allocated_quantity' => 1,
                'released_quantity' => 0,
                'status' => 'ACTIVE',
                'allocated_at' => now(),
            ]);
        }

        return $request->fresh();
    }

    private function borrower(): User
    {
        return User::query()->where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
    }
}
