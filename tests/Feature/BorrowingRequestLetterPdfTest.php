<?php

namespace Tests\Feature;

use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\DocumentService;
use App\Services\ProtectedFileService;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowingRequestLetterPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_ccs_orsem_sized_request_renders_as_a_formal_single_page_pdf(): void
    {
        $this->seed(DatabaseSeeder::class);
        $borrower = User::query()->where('email', 'borrower@spmu.test')->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-CCS-ORSEM-TEST',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'CCS Orsem',
            'event_details' => 'Orientation seminar for new College of Computer Studies students, including introduction to college policies, faculty, facilities, student services, and academic programs.',
            'location' => 'CSPC Gymnasium',
            'needed_from' => CarbonImmutable::parse('2026-08-17 08:00:00', 'Asia/Manila'),
            'return_due_at' => CarbonImmutable::parse('2026-08-18 07:00:00', 'Asia/Manila'),
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $requestedItems = [
            'LED Wall' => 1,
            'Microphones' => 2,
            'Rectangular Table' => 4,
            'Rectangular Table Cloth - Yellow' => 4,
            'Monoblock Chairs' => 100,
            'Barricade' => 2,
        ];
        foreach ($requestedItems as $description => $quantity) {
            $inventoryItem = InventoryItem::query()->where('unique_description', $description)->firstOrFail();
            RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $inventoryItem->id,
                'description_snapshot' => $inventoryItem->unique_description,
                'unit_snapshot' => $inventoryItem->unit->unit_name,
                'requested_quantity' => $quantity,
                'use_location' => 'ON_CAMPUS',
            ]);
        }

        $signature = app(SignatureService::class)->snapshot($borrower, 'BORROWING_REQUEST_CERTIFICATION', 'BORROWER');
        $version->update([
            'borrower_signature_snapshot_id' => $signature->id,
            'accuracy_certified' => true,
            'signed_at' => CarbonImmutable::parse('2026-08-14 12:50:00', 'Asia/Manila'),
        ]);

        $request = $request->fresh();
        $service = app(DocumentService::class);
        $html = $service->requestLetterHtml(
            $request,
            false,
            CarbonImmutable::parse('2026-08-14 12:50:00', 'Asia/Manila'),
        );

        $this->assertSame(1, substr_count($html, '<h1 class="document-title">Borrowing Request Letter</h1>'));
        $this->assertStringContainsString('Borrower / Event Information', $html);
        $this->assertStringContainsString('<table class="items-table"', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('Borrower Certification', $html);
        $this->assertStringContainsString('17 August 2026, 8:00 a.m.', $html);
        $this->assertStringContainsString('Accountable Borrower', $html);
        $this->assertStringContainsString('Borrower', $html);
        $this->assertStringContainsString('/s/ Borrower Demo', $html);
        $this->assertStringNotContainsString('Borrower only', $html);
        $this->assertStringNotContainsString('BORROWER_ONLY', $html);
        $this->assertStringNotContainsString('<table class="reference"', $html);
        $this->assertStringNotContainsString('LED Wall |', $html);

        $document = $service->requestLetter($request, false);
        $bytes = app(ProtectedFileService::class)->bytes($document->file);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('/Count 1', $bytes, 'The six-item CCS ORSEM reference should fit on one A4 page.');
        $this->assertSame('REQUEST_LETTER', $document->document_type);
        $this->assertSame('DRAFT', $document->status);
    }

    public function test_long_item_table_paginates_instead_of_being_forced_onto_one_page(): void
    {
        $this->seed(DatabaseSeeder::class);
        $borrower = User::query()->where('email', 'borrower@spmu.test')->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-LONG-PDF-TEST',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Institution-wide equipment requirement',
            'event_details' => str_repeat('This longer activity description verifies natural wrapping and multi-page document flow. ', 3),
            'location' => 'CSPC Campus',
            'needed_from' => now()->addWeek(),
            'return_due_at' => now()->addWeek()->addDay(),
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        foreach (InventoryItem::query()->with('unit')->get() as $inventoryItem) {
            RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $inventoryItem->id,
                'description_snapshot' => $inventoryItem->unique_description,
                'unit_snapshot' => $inventoryItem->unit->unit_name,
                'requested_quantity' => 1,
                'use_location' => 'ON_CAMPUS',
            ]);
        }

        $document = app(DocumentService::class)->requestLetter($request->fresh(), false);
        $bytes = app(ProtectedFileService::class)->bytes($document->file);

        $this->assertMatchesRegularExpression(
            '/\/Count\s+(?:[2-9]|[1-9][0-9]+)\b/',
            $bytes,
            'A request containing the complete inventory list should paginate naturally.',
        );
    }

    public function test_authorized_raster_signature_snapshot_is_embedded_in_the_letter(): void
    {
        $this->seed(DatabaseSeeder::class);
        $borrower = User::query()->where('email', 'borrower@spmu.test')->firstOrFail();
        $borrower->currentSignature()->update(['status' => 'REPLACED', 'effective_to' => now()]);

        $file = app(ProtectedFileService::class)->storeBytes(
            (string) file_get_contents(resource_path('images/cspc-logo-print.jpg')),
            'test-signatures',
            'signature.jpg',
            'image/jpeg',
            'jpg',
            'PROFILE_SIGNATURE',
            $borrower->id,
        );
        UserSignature::query()->create([
            'user_id' => $borrower->id,
            'stored_file_id' => $file->id,
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-RASTER-SIGNATURE-TEST',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Signature rendering test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->addWeek(),
            'return_due_at' => now()->addWeek()->addDay(),
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);
        $snapshot = app(SignatureService::class)->snapshot($borrower, 'BORROWING_REQUEST_CERTIFICATION', 'BORROWER');
        $version->update(['borrower_signature_snapshot_id' => $snapshot->id, 'signed_at' => now()]);

        $html = app(DocumentService::class)->requestLetterHtml($request->fresh());

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('/s/ Borrower Demo', $html);
    }

    public function test_final_letter_uses_formal_signature_blocks_for_all_approvals(): void
    {
        $this->seed(DatabaseSeeder::class);
        $borrower = User::query()->where('email', 'borrower@spmu.test')->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-FINAL-APPROVALS-TEST',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::FinalApprovedAwaitingDownload,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Final approval presentation test',
            'location' => 'CSPC Campus',
            'needed_from' => CarbonImmutable::parse('2026-08-20 08:00:00', 'Asia/Manila'),
            'return_due_at' => CarbonImmutable::parse('2026-08-21 17:00:00', 'Asia/Manila'),
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $approvers = [
            [ApprovalStage::Spmu, 'spmu-head@spmu.test'],
            [ApprovalStage::Gsu, 'gsu@spmu.test'],
            [ApprovalStage::Vpaf, 'vpaf@spmu.test'],
        ];
        foreach ($approvers as $index => [$stage, $email]) {
            $approver = User::query()->where('email', $email)->firstOrFail();
            $snapshot = app(SignatureService::class)->snapshot($approver, 'APPROVAL_'.$stage->value, $stage->value);
            $version->approvalSteps()->create([
                'approver_user_id' => $approver->id,
                'stage_code' => $stage,
                'sequence_no' => $index + 1,
                'received_at' => CarbonImmutable::parse('2026-08-14 13:00:00', 'Asia/Manila')->addMinutes($index * 10),
                'decision' => 'APPROVED',
                'decided_at' => CarbonImmutable::parse('2026-08-14 13:05:00', 'Asia/Manila')->addMinutes($index * 10),
                'signature_snapshot_id' => $snapshot->id,
            ]);
        }

        $html = app(DocumentService::class)->requestLetterHtml($request->fresh(), true);

        $this->assertSame(3, substr_count($html, 'class="approval-signature-block"'));
        $this->assertStringContainsString('Supply and Property Management Unit', $html);
        $this->assertStringContainsString('General Services Unit', $html);
        $this->assertStringContainsString('Vice President for Administration and Finance', $html);
        $this->assertStringContainsString('/s/ SPMU Head Demo', $html);
        $this->assertStringContainsString('Approved on 14 August 2026, 1:05 p.m.', $html);
        $this->assertStringNotContainsString('Digital Approvals', $html);
    }
}
