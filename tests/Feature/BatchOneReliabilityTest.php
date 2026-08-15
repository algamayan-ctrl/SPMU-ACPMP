<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\EvidenceSubmission;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CustodyService;
use App\Services\DocumentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BatchOneReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_draft_request_survives_document_generation_failure(): void
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()->where('active', true)->where('borrowable', true)->firstOrFail();
        $this->mock(DocumentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestLetter')->once()->andThrow(new RuntimeException('Simulated document storage failure.'));
        });

        $this->withoutExceptionHandling();
        try {
            $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->post(route('requests.store'), [
                'purpose_event' => 'Document recovery test',
                'location' => 'Campus',
                'needed_from' => now()->addDay()->format('Y-m-d H:i:s'),
                'return_due_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'event_details' => 'The database record must survive a failed preview write.',
                'item_ids' => [$item->id],
                'quantities' => [$item->id => 1],
                'locations' => [$item->id => 'ON_CAMPUS'],
            ]);
            $this->fail('The simulated document failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated document storage failure.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $request = BorrowingRequest::query()->where('borrower_user_id', $borrower->id)->firstOrFail();
        $this->assertSame(RequestStatus::Draft, $request->status);
        $this->assertDatabaseHas('request_versions', ['request_id' => $request->id, 'version_no' => 1]);
        $this->assertDatabaseHas('request_items', ['request_version_id' => $request->currentVersion->id, 'inventory_item_id' => $item->id]);
        $this->assertDatabaseMissing('generated_documents', ['request_version_id' => $request->currentVersion->id, 'document_type' => 'REQUEST_LETTER']);
    }

    public function test_missing_preview_can_be_regenerated_once_without_duplication(): void
    {
        [$request] = $this->draftRequest('BR-RECOVER-001');
        $borrower = $request->borrower;

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSee('Regenerate missing preview');

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->post(route('requests.recover-draft-document', $request))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'The missing draft request-letter preview was regenerated successfully.');

        $this->assertSame(1, GeneratedDocument::query()
            ->where('request_version_id', $request->currentVersion->id)
            ->where('document_type', 'REQUEST_LETTER')
            ->where('status', 'DRAFT')
            ->count());

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->post(route('requests.recover-draft-document', $request))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'The draft request-letter preview is already available. No duplicate was created.');

        $this->assertSame(1, GeneratedDocument::query()
            ->where('request_version_id', $request->currentVersion->id)
            ->where('document_type', 'REQUEST_LETTER')
            ->where('status', 'DRAFT')
            ->count());
    }

    public function test_earlier_incident_keeps_custody_open_after_final_fine_return(): void
    {
        [$custody, $lines] = $this->activeCustodyWithLines(2);
        $officer = $this->spmuOfficer();
        $evidence = StoredFile::query()->create([
            'uploaded_by_user_id' => $officer->id,
            'disk' => 'local',
            'storage_path' => 'test-evidence/'.uniqid().'.pdf',
            'original_name' => 'damage.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1,
            'sha256' => hash('sha256', 'x'),
            'classification' => 'INCIDENT_EVIDENCE',
        ]);

        app(CustodyService::class)->receiveReturn(
            $custody,
            $officer,
            [$lines[0]->id => 1],
            [$lines[0]->id => 'DAMAGED'],
            'Damage found during the first partial return.',
            evidenceFileIds: [$lines[0]->id => $evidence->id],
        );
        $this->assertSame('PARTIALLY_RETURNED', $custody->fresh()->status);
        $this->travel(1)->seconds();

        app(CustodyService::class)->receiveReturn(
            $custody->fresh(),
            $officer,
            [$lines[1]->id => 1],
            [$lines[1]->id => 'FINE'],
            'Final serviceable item returned.',
        );

        $this->assertDatabaseHas('incidents', ['custody_transaction_id' => $custody->id, 'status' => 'OPEN']);
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);
        $this->assertNull($custody->fresh()->closed_at);
    }

    public function test_replayed_conditional_signatures_do_not_create_duplicates(): void
    {
        [$custody, $gatePass] = $this->gatePassCustody(false);
        $officer = $this->spmuOfficer();
        $head = User::query()->where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)
            ->post(route('gate-passes.sign-verified', $gatePass))->assertSessionHasNoErrors();
        $afterOfficerDocuments = $this->gatePassDocumentCount($custody);
        $afterOfficerSnapshots = DB::table('signature_snapshots')->count();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)
            ->post(route('gate-passes.sign-verified', $gatePass))->assertSessionHasNoErrors();
        $this->assertSame($afterOfficerDocuments, $this->gatePassDocumentCount($custody));
        $this->assertSame($afterOfficerSnapshots, DB::table('signature_snapshots')->count());

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)
            ->post(route('gate-passes.sign-approved', $gatePass))->assertSessionHasNoErrors();
        $afterHeadDocuments = $this->gatePassDocumentCount($custody);
        $afterHeadSnapshots = DB::table('signature_snapshots')->count();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)
            ->post(route('gate-passes.sign-approved', $gatePass))->assertSessionHasNoErrors();
        $this->assertSame($afterHeadDocuments, $this->gatePassDocumentCount($custody));
        $this->assertSame($afterHeadSnapshots, DB::table('signature_snapshots')->count());
    }

    public function test_repeated_evidence_verification_is_idempotent(): void
    {
        [$custody, $gatePass, $document] = $this->gatePassCustody(true);
        $borrower = $custody->borrower;
        $officer = $this->spmuOfficer();

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->post(route('evidence.store', $document), [
            'evidence' => UploadedFile::fake()->create('signed-gate-pass.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $evidence = EvidenceSubmission::query()->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)->post(route('evidence.verify', $evidence), [
            'decision' => 'VERIFIED',
        ])->assertSessionHasNoErrors();
        $auditCount = DB::table('audit_events')->where('action_code', 'PAPER_EVIDENCE_VERIFIED')->count();
        $verifiedAt = $evidence->fresh()->verified_at;

        $this->travel(1)->minute();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($officer)->post(route('evidence.verify', $evidence), [
            'decision' => 'VERIFIED',
        ])->assertSessionHasNoErrors();

        $this->assertSame($auditCount, DB::table('audit_events')->where('action_code', 'PAPER_EVIDENCE_VERIFIED')->count());
        $this->assertTrue($verifiedAt->equalTo($evidence->fresh()->verified_at));
    }

    public function test_superseded_document_cannot_be_downloaded_as_current(): void
    {
        [$request] = $this->draftRequest('BR-SUPERSEDED-001');
        $document = app(DocumentService::class)->requestLetter($request, false);
        $document->update(['status' => 'SUPERSEDED', 'invalidated_at' => now(), 'invalidation_reason' => 'Replaced for test.']);

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($request->borrower)
            ->get(route('documents.download', $document))
            ->assertStatus(410);
    }

    public function test_evidence_cannot_be_uploaded_to_superseded_or_wrong_custody_forms(): void
    {
        [$custody, , $document] = $this->gatePassCustody(true);
        $borrower = $custody->borrower;
        $document->update(['status' => 'SUPERSEDED', 'invalidated_at' => now(), 'invalidation_reason' => 'Replaced for test.']);

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->post(route('evidence.store', $document), [
            'evidence' => UploadedFile::fake()->create('obsolete.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('evidence');

        [$otherRequest, $otherVersion] = $this->draftRequest('BR-WRONG-CUSTODY-001');
        $wrong = GeneratedDocument::query()->create([
            'stored_file_id' => $document->stored_file_id,
            'request_version_id' => $otherVersion->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'WRONG-CUSTODY-001',
            'document_type' => 'GATE_PASS',
            'version_no' => 99,
            'sha256' => $document->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)->post(route('evidence.store', $wrong), [
            'evidence' => UploadedFile::fake()->create('wrong.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('evidence');

        $this->assertSame(0, EvidenceSubmission::query()->count());
        $this->assertSame(RequestStatus::Draft, $otherRequest->fresh()->status);
    }

    public function test_authenticated_user_is_logged_out_after_account_deactivation(): void
    {
        $borrower = $this->borrower();
        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->get(route('dashboard'))->assertOk();

        $borrower->update(['account_status' => AccountStatus::Inactive]);

        $this->withSession(['active_workspace' => 'BORROWER'])->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** @return array{BorrowingRequest, RequestVersion} */
    private function draftRequest(string $requestNo): array
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()->where('active', true)->where('borrowable', true)->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => $requestNo,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Batch one reliability test',
            'location' => 'Campus',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'event_details' => 'Focused reliability fixture.',
            'created_by_user_id' => $borrower->id,
        ]);
        RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'use_location' => 'ON_CAMPUS',
        ]);

        return [$request->fresh(), $version];
    }

    /** @return array{CustodyTransaction, array<int, CustodyLine>} */
    private function activeCustodyWithLines(int $count): array
    {
        $borrower = $this->borrower();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-CUSTODY-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Return closeout test',
            'location' => 'Campus',
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->addDay(),
            'created_by_user_id' => $borrower->id,
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $items = InventoryItem::query()->where('active', true)->where('borrowable', true)->where('laundry_required', false)->limit($count)->get();
        $this->assertCount($count, $items);
        $lines = [];
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
            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $version->needed_from,
                'period_end' => $version->return_due_at,
                'allocated_quantity' => 1,
                'released_quantity' => 1,
                'status' => 'RELEASED',
                'allocated_at' => now()->subDay(),
            ]);
            $lines[] = CustodyLine::query()->create([
                'custody_transaction_id' => $custody->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => 1,
                'quantity_to_receive' => 1,
                'actual_released_quantity' => 1,
                'returned_quantity' => 0,
                'item_status' => 'RELEASED_PENDING_RETURN',
            ]);
        }

        return [$custody->fresh(), $lines];
    }

    /** @return array{CustodyTransaction, GatePass, GeneratedDocument} */
    private function gatePassCustody(bool $released): array
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()->where('off_campus_allowed', true)->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-GATE-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Off-campus test',
            'location' => 'Off-campus venue',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'off_campus' => true,
            'created_by_user_id' => $borrower->id,
        ]);
        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'approved_quantity' => 1,
            'use_location' => 'OFF_CAMPUS',
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 1,
            'released_quantity' => $released ? 1 : 0,
            'status' => $released ? 'RELEASED' : 'ACTIVE',
            'allocated_at' => now(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-GATE-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $released ? 'ACTIVE' : 'PREPARING_RELEASE',
            'released_at' => $released ? now() : null,
            'due_at' => $version->return_due_at,
        ]);
        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => 0,
            'item_status' => $released ? 'RELEASED_PENDING_RETURN' : 'CONFIRMED',
            'compliance_status' => 'AWAITING_GUARD_SIGNATURE',
        ]);
        $document = app(DocumentService::class)->conditionalForm($custody, 'GATE_PASS');
        $gatePass = GatePass::query()->create([
            'custody_transaction_id' => $custody->id,
            'custody_line_id' => $line->id,
            'pass_document_id' => $document->id,
            'bearer_name' => $borrower->full_name,
            'destination' => $version->location,
            'purpose' => $version->purpose_event,
            'status' => $released ? 'READY_FOR_PRINTING' : 'PENDING',
            'approved_at' => $released ? now() : null,
        ]);

        return [$custody->fresh(), $gatePass->fresh(), $document->fresh()];
    }

    private function gatePassDocumentCount(CustodyTransaction $custody): int
    {
        return GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', 'GATE_PASS')
            ->count();
    }

    private function borrower(): User
    {
        return User::query()->where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
    }

    private function spmuOfficer(): User
    {
        return User::query()->where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();
    }
}
