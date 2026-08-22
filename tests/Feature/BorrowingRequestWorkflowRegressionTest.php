<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestSupportingDocument;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BorrowingRequestWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_unrestricted_borrower_saves_draft_without_event_details_and_generates_letter(): void
    {
        $borrower = $this->borrower();
        $request = $this->createDraft($borrower);
        $version = $request->currentVersion;

        $this->assertSame(RequestStatus::Draft, $request->status);
        $this->assertNull($version->event_details);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseHas('request_items', [
            'request_version_id' => $version->id,
            'requested_quantity' => 1,
        ]);
        $this->assertDatabaseHas('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'REQUEST_LETTER',
            'status' => 'DRAFT',
        ]);

        $this->borrowerRequest($borrower, 'get', route('requests.show', $request))
            ->assertOk()
            ->assertSee('name="approved_request_letter"', false)
            ->assertDontSee('name="permission_to_conduct_letter"', false);
    }

    public function test_active_restriction_does_not_block_draft_or_generated_letter(): void
    {
        $borrower = $this->borrower();
        $this->restrict($borrower);

        $request = $this->createDraft($borrower);

        $this->assertSame(RequestStatus::Draft, $request->status);
        $this->assertDatabaseHas('generated_documents', [
            'request_version_id' => $request->currentVersion->id,
            'document_type' => 'REQUEST_LETTER',
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_active_restriction_blocks_submission_without_creating_reservation(): void
    {
        $borrower = $this->borrower();
        $this->restrict($borrower);
        $request = $this->createDraft($borrower);
        $this->uploadDocuments($request, $borrower);

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.submit', $request)
        )
            ->assertSessionHasErrors([
                'restriction' =>
                    'Borrowing is currently restricted: Unresolved accountability test.',
            ]);

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('approval_steps', 0);
    }

    public function test_submission_without_scanned_signed_request_letter_is_rejected(): void
    {
        $borrower = $this->borrower();
        $request = $this->createDraft($borrower);

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.submit', $request)
        )->assertSessionHasErrors('approved_request_letter');

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_student_activity_submission_without_permission_to_conduct_is_rejected(): void
    {
        $borrower = $this->borrower();
        $request = $this->createDraft($borrower, true);
        $this->uploadDocuments($request, $borrower);

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.submit', $request)
        )->assertSessionHasErrors('permission_to_conduct_letter');

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_student_activity_with_all_documents_submits_without_reservation(): void
    {
        $borrower = $this->borrower();
        $request = $this->createDraft($borrower, true);

        $this->borrowerRequest($borrower, 'get', route('requests.show', $request))
            ->assertOk()
            ->assertSee('name="permission_to_conduct_letter"', false);

        $this->uploadDocuments($request, $borrower, true);

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.submit', $request)
        )->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $request->currentVersion->id,
            'decision' => 'RECEIVED',
        ]);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_non_student_submission_needs_no_ptc_and_spmu_approval_creates_reservation(): void
    {
        $borrower = $this->borrower();
        $request = $this->createDraft($borrower);
        $this->uploadDocuments($request, $borrower);

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.submit', $request)
        )->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);

        $spmuHead = User::query()
            ->where(
                'access_classification',
                AccessClassification::SpmuHead->value
            )
            ->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuHead)
            ->post(
                route('approvals.decide', $request),
                [
                    'decision' => 'APPROVED',
                    'details_complete' => '1',
                    'signatures_present' => '1',
                    'document_readable' => '1',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            RequestStatus::ApprovedReadyForRelease,
            $request->fresh()->status
        );
        $this->assertDatabaseHas('allocations', [
            'request_item_id' => $request
                ->currentVersion
                ->items()
                ->firstOrFail()
                ->id,
            'allocated_quantity' => 1,
            'status' => 'ACTIVE',
        ]);
    }

    private function createDraft(
        User $borrower,
        bool $studentActivity = false
    ): BorrowingRequest {
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('condition_code', 'SERVICEABLE')
            ->firstOrFail();

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.store'),
            [
                'purpose_event' => 'Workflow regression test',
                'location' => 'Campus',
                'schedule_date' => now()->addDays(2)->toDateString(),
                'return_date' => now()->addDays(3)->toDateString(),
                'represents_student_activity' => $studentActivity ? '1' : '0',
                'student_organization' => $studentActivity
                    ? 'Student Council'
                    : null,
                'represented_program_department' =>
                    'Information Technology Office',
                'item_ids' => [$item->id],
                'quantities' => [$item->id => 1],
                'locations' => [$item->id => 'ON_CAMPUS'],
            ]
        )
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return BorrowingRequest::query()
            ->where('borrower_user_id', $borrower->id)
            ->latest('id')
            ->with('currentVersion.items')
            ->firstOrFail();
    }

    private function uploadDocuments(
        BorrowingRequest $request,
        User $borrower,
        bool $withPermission = false
    ): void {
        $payload = [
            'approved_request_letter' => UploadedFile::fake()->create(
                'signed-request-letter.pdf',
                10,
                'application/pdf'
            ),
        ];

        if ($withPermission) {
            $payload['permission_to_conduct_letter'] =
                UploadedFile::fake()->create(
                    'permission-to-conduct.pdf',
                    10,
                    'application/pdf'
                );
        }

        $this->borrowerRequest(
            $borrower,
            'post',
            route('requests.supporting-documents.store', $request),
            $payload
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('request_supporting_documents', [
            'request_id' => $request->id,
            'document_type' => RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'is_current' => true,
        ]);

        if ($withPermission) {
            $this->assertDatabaseHas('request_supporting_documents', [
                'request_id' => $request->id,
                'document_type' =>
                    RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
                'is_current' => true,
            ]);
        }
    }

    private function restrict(User $borrower): void
    {
        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'ACCOUNTABILITY',
            'reason' => 'Unresolved accountability test.',
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function borrower(): User
    {
        $borrower = User::query()
            ->whereHas(
                'roles',
                fn ($query) => $query
                    ->where('role_code', UserRole::Borrower->value)
                    ->whereNull('user_roles.revoked_at')
            )
            ->firstOrFail();

        $borrower->activeRestrictions()->delete();

        return $borrower;
    }

    private function borrowerRequest(
        User $borrower,
        string $method,
        string $uri,
        array $data = []
    ): TestResponse {
        return $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->{$method}($uri, $data);
    }
}
