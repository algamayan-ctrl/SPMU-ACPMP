<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\RequestItem;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimpleLaundryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_laundry_worker_has_a_simple_single_purpose_workspace(): void
    {
        [$job] = $this->laundryCase();
        $worker = $this->classificationUser(AccessClassification::LaundryWorker);

        $this->withSession(['active_workspace' => 'LAUNDRY'])
            ->actingAs($worker)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Simple Laundry Mode')
            ->assertSeeText('Only two system actions are needed.')
            ->assertSeeText($job->custody->custody_no);

        $this->withSession(['active_workspace' => 'LAUNDRY'])
            ->actingAs($worker)
            ->get(route('laundry.show', $job))
            ->assertOk()
            ->assertSeeText('Upload accomplished Laundry Form')
            ->assertSeeText('View / Print Laundry Form')
            ->assertSeeText('You do not need to encode the handwritten details in the system.');
    }

    public function test_only_laundry_worker_uploads_the_accomplished_form_and_upload_marks_ready_for_pickup(): void
    {
        [$job, $line, $borrower] = $this->laundryCase();
        $worker = $this->classificationUser(AccessClassification::LaundryWorker);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('laundry.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'LAUNDRY'])
            ->actingAs($worker)
            ->post(route('laundry.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $line->refresh();

        $this->assertSame('READY_FOR_PICKUP', $job->status);
        $this->assertNotNull($job->ready_at);
        $this->assertNotNull($job->latest_evidence_submission_id);

        $this->assertDatabaseHas('evidence_submissions', [
            'id' => $job->latest_evidence_submission_id,
            'uploaded_by_user_id' => $worker->id,
            'upload_mode' => 'LAUNDRY_WORKER',
            'verification_status' => 'PENDING_VERIFICATION',
        ]);

        /*
         * Laundry does not encode the form details. These fields remain empty
         * until an SPMU Action Officer transcribes the signed physical form.
         */
        $this->assertNull($line->received_quantity);
        $this->assertNull($line->issue_type);
        $this->assertNull($line->completed_quantity);

        $this->assertDatabaseHas('notification_events', [
            'event_code' => 'LAUNDRY_READY_FOR_PICKUP',
        ]);
    }

    public function test_spmu_transcribes_the_scan_and_only_final_spmu_return_makes_linen_available(): void
    {
        [$job, $jobLine] = $this->laundryCase();
        $worker = $this->classificationUser(AccessClassification::LaundryWorker);
        $spmu = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->withSession(['active_workspace' => 'LAUNDRY'])
            ->actingAs($worker)
            ->post(route('laundry.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
            ])
            ->assertSessionHasNoErrors();

        /* Upload / Laundry completion alone must never restore inventory. */
        $this->assertDatabaseMissing('inventory_transaction_lines', [
            'inventory_item_id' => $jobLine->custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
        ]);

        $this->withSession(['active_workspace' => 'LAUNDRY'])
            ->actingAs($worker)
            ->post(route('laundry.release-to-borrower', $job))
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('FOR_SPMU_FINAL_CHECK', $job->status);
        $this->assertNotNull($job->released_to_borrower_at);

        $receivedAt = now()->subHours(3);
        $completedAt = now()->subHour();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('laundry.verify-form', $job), [
                'decision' => 'VERIFIED',
                'worker_name' => 'Laundry Worker Demo',
                'worker_received_at' => $receivedAt->format('Y-m-d H:i:s'),
                'worker_completed_at' => $completedAt->format('Y-m-d H:i:s'),
                'worker_remarks' => 'One stain was treated; linen completed.',
                'lines' => [
                    $jobLine->id => [
                        'received_quantity' => 2,
                        'issue_type' => 'STAINED',
                        'affected_quantity' => 1,
                        'completed_quantity' => 2,
                        'remarks' => 'One piece arrived stained and was cleaned.',
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $jobLine->refresh();

        $this->assertNotNull($job->form_verified_at);
        $this->assertSame($spmu->id, $job->form_verified_by_user_id);
        $this->assertSame('Laundry Worker Demo', $job->worker_name);
        $this->assertSame('STAINED', $jobLine->issue_type);
        $this->assertSame(1.0, (float) $jobLine->affected_quantity);
        $this->assertSame(2.0, (float) $jobLine->completed_quantity);

        /* SPMU form verification still does not make the asset Available. */
        $this->assertDatabaseMissing('inventory_transaction_lines', [
            'inventory_item_id' => $jobLine->custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
        ]);

        $custodyLine = $jobLine->custodyLine;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('custody.return', $job->custody), [
                'quantities' => [
                    $custodyLine->id => 2,
                ],
                'conditions' => [
                    $custodyLine->id => 'FINE',
                ],
                'remarks' => 'Cleaned linen physically received and inspected by SPMU.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_transaction_lines', [
            'inventory_item_id' => $custodyLine->requestItem->inventory_item_id,
            'from_state' => 'BORROWED',
            'to_state' => 'AVAILABLE',
            'quantity' => 2,
        ]);

        $this->assertSame('LAUNDRY_COMPLETED', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->completed_at);
    }

    /**
     * @return array{LaundryJob, LaundryJobLine, User}
     */
    private function laundryCase(): array
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $item = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', true)
            ->firstOrFail();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-LAUNDRY-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Simple linen workflow test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->addDay(),
            'event_details' => 'Borrower carries used linen to Laundry and cleaned linen back to SPMU.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 2,
            'approved_quantity' => 2,
            'use_location' => 'ON_CAMPUS',
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 2,
            'released_quantity' => 2,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDay(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-LAUNDRY-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now()->subHours(6),
            'due_at' => now()->addDay(),
        ]);

        $custodyLine = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 2,
            'quantity_to_receive' => 2,
            'actual_released_quantity' => 2,
            'returned_quantity' => 0,
            'release_condition' => 'SERVICEABLE',
            'item_status' => 'RELEASED_PENDING_RETURN',
            'compliance_status' => 'FOR_LAUNDRY',
        ]);

        $formBytes = '%PDF-1.4 simple laundry form';
        $formPath = 'tests/laundry/'.uniqid().'.pdf';
        Storage::disk('local')->put($formPath, $formBytes);

        $storedFile = StoredFile::query()->create([
            'uploaded_by_user_id' => null,
            'disk' => 'local',
            'storage_path' => $formPath,
            'original_name' => 'laundry-form.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($formBytes),
            'sha256' => hash('sha256', $formBytes),
            'classification' => 'CONTROLLED_DOCUMENT',
        ]);

        $document = GeneratedDocument::query()->create([
            'stored_file_id' => $storedFile->id,
            'request_version_id' => $version->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-LAUNDRY-'.uniqid(),
            'document_type' => 'LAUNDRY_FORM',
            'version_no' => 1,
            'sha256' => $storedFile->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        $job = LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'generated_document_id' => $document->id,
            'status' => 'FOR_LAUNDRY',
        ]);

        $jobLine = LaundryJobLine::query()->create([
            'laundry_job_id' => $job->id,
            'custody_line_id' => $custodyLine->id,
            'issued_quantity' => 2,
            'affected_quantity' => 0,
        ]);

        return [
            $job->fresh(['custody.borrower', 'custody.request']),
            $jobLine->fresh(['custodyLine.requestItem']),
            $borrower,
        ];
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
