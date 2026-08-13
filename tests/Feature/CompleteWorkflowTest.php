<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_complete_five_role_approval_download_and_release_workflow(): void
    {
        $borrower = $this->roleUser(UserRole::Borrower);
        $spmu = $this->classificationUser(AccessClassification::SpmuHead);
        $spmuOfficer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $gsu = $this->roleUser(UserRole::Gsu);
        $vpaf = $this->roleUser(UserRole::Vpaf);
        $item = InventoryItem::where('unique_description', 'Monoblock Chairs')->firstOrFail();

        $request = BorrowingRequest::create([
            'request_no' => 'BR-FLOW-001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Institutional orientation',
            'location' => 'CSPC Gymnasium',
            'needed_from' => now()->addDays(2),
            'return_due_at' => now()->addDays(3),
            'event_details' => 'Employee-accountable student orientation activity.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);
        RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 20,
        ]);

        $this->actingAs($borrower)->post(route('requests.submit', $request))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertDatabaseCount('approval_steps', 3);
        $this->assertDatabaseCount('allocations', 0);

        $this->actingAs($spmu)->post(route('approvals.decide', $request), ['decision' => 'APPROVED'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::UnderGsu, $request->fresh()->status);
        $this->actingAs($gsu)->post(route('approvals.decide', $request), ['decision' => 'APPROVED'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::UnderVpaf, $request->fresh()->status);
        $this->actingAs($vpaf)->post(route('approvals.decide', $request), ['decision' => 'APPROVED'])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(RequestStatus::FinalApprovedAwaitingDownload, $request->status);
        $this->assertDatabaseHas('allocations', ['allocated_quantity' => 20, 'status' => 'ACTIVE']);
        $this->assertDatabaseMissing('custody_transactions', ['request_id' => $request->id]);

        $letter = GeneratedDocument::where('request_version_id', $version->id)->where('document_type', 'APPROVED_REQUEST_LETTER')->where('status', 'FINAL')->firstOrFail();
        $this->actingAs($borrower)->get(route('documents.download', $letter))->assertOk();
        $this->actingAs($borrower)->get(route('documents.download', $letter))->assertOk();
        $this->assertDatabaseCount('download_events', 1);
        $request->refresh();
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->status);
        $custody = CustodyTransaction::where('request_id', $request->id)->firstOrFail();
        $this->assertDatabaseHas('generated_documents', ['subject_id' => $custody->id, 'document_type' => 'BORROWER_SLIP', 'status' => 'FINAL']);

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.prepare', $custody))->assertSessionHasNoErrors();
        $this->actingAs($borrower)->post(route('custody.acknowledge', $custody))->assertSessionHasNoErrors();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.release', $custody))->assertSessionHasNoErrors();
        $this->assertSame('ACTIVE', $custody->fresh()->status);
        $this->assertDatabaseHas('custody_lines', ['custody_transaction_id' => $custody->id, 'actual_released_quantity' => 20]);
        $this->assertDatabaseHas('inventory_transaction_lines', ['from_state' => 'ALLOCATED', 'to_state' => 'BORROWED', 'quantity' => 20]);
    }

    public function test_borrower_can_reduce_release_quantity_and_unused_allocation_is_restored(): void
    {
        [$borrower, $spmu, $spmuOfficer, $gsu, $vpaf, $request] = $this->approvedDownloadedRequest(12);
        $custody = $request->custody()->with('lines')->firstOrFail();
        $line = $custody->lines->first();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.quantities', $custody), [
            'quantities' => [$line->id => 8],
            'reasons' => [$line->id => 'Event attendance was reduced.'],
        ])->assertSessionHasNoErrors();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.prepare', $custody))->assertSessionHasNoErrors();
        $this->actingAs($borrower)->post(route('custody.acknowledge', $custody))->assertSessionHasNoErrors();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.release', $custody))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('allocations', ['released_quantity' => 8, 'restored_quantity' => 4, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('inventory_transaction_lines', ['from_state' => 'ALLOCATED', 'to_state' => 'AVAILABLE', 'quantity' => 4]);
    }

    public function test_ictu_cannot_act_on_official_approval_queue(): void
    {
        $ictu = $this->roleUser(UserRole::Ictu);

        $this->withSession(['active_workspace' => 'ICTU'])->actingAs($ictu)->get('/approvals')->assertForbidden();
        $this->withSession(['active_workspace' => 'ICTU'])->actingAs($ictu)->get('/administration/users')->assertOk();
    }

    public function test_overdue_tariff_billing_payment_and_closeout(): void
    {
        [$borrower, $spmu, $spmuOfficer, , , $request] = $this->approvedDownloadedRequest(5);
        $custody = $request->custody()->with('lines')->firstOrFail();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.prepare', $custody));
        $this->actingAs($borrower)->post(route('custody.acknowledge', $custody));
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.release', $custody));
        $custody->update(['due_at' => now()->subHours(47)]);
        SystemSetting::where('setting_key', 'daily_overdue_tariff')->firstOrFail()->update(['value_json' => 75]);

        $this->artisan('spmu:process-deadlines')->assertSuccessful();
        $this->assertSame('OVERDUE', $custody->fresh()->status);
        $this->assertDatabaseHas('borrower_restrictions', ['borrower_user_id' => $borrower->id, 'restriction_type' => 'OVERDUE_RETURN', 'status' => 'ACTIVE']);

        $line = $custody->lines->first();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.return', $custody), [
            'quantities' => [$line->id => 5],
            'conditions' => [$line->id => 'FINE'],
            'remarks' => 'Complete overdue return.',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('overdue_cases', ['custody_transaction_id' => $custody->id, 'accrued_amount' => 75, 'status' => 'RETURNED_PENDING_SETTLEMENT']);

        $overdue = $custody->overdueCase()->firstOrFail();
        $this->actingAs($spmu)->post(route('overdue.bill', $overdue), ['basis' => 'Approved daily tariff after grace period.'])->assertSessionHasNoErrors();
        $billing = BillingStatement::where('borrower_user_id', $borrower->id)->firstOrFail();
        $billingDocument = GeneratedDocument::where('subject_type', BillingStatement::class)->where('subject_id', $billing->id)->firstOrFail();
        $this->actingAs($borrower)->get(route('documents.download', $billingDocument))->assertOk();
        $this->actingAs($borrower)->post(route('payments.store', $billing), [
            'evidence' => UploadedFile::fake()->create('official-receipt.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $payment = $billing->payments()->firstOrFail();
        $this->actingAs($borrower)->get(route('files.show', $payment->evidence_file_id))->assertOk();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('payments.verify', $payment), [
            'decision' => 'VERIFIED', 'official_receipt_no' => 'OR-OVERDUE-001',
            'receipt_date' => now()->toDateString(), 'amount' => 75, 'remarks' => 'Original inspected.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('SETTLED', $billing->fresh()->status);
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertDatabaseHas('borrower_restrictions', ['borrower_user_id' => $borrower->id, 'restriction_type' => 'OVERDUE_RETURN', 'status' => 'LIFTED']);
    }

    public function test_stolen_property_requires_blotter_and_evidence_and_can_generate_approved_rslddp(): void
    {
        SystemSetting::where('setting_key', 'rslddp_template_status')->firstOrFail()->update(['value_json' => 'APPROVED']);
        [$borrower, $spmu, $spmuOfficer, , , $request] = $this->approvedDownloadedRequest(2);
        $custody = $request->custody()->with('lines')->firstOrFail();
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.prepare', $custody));
        $this->actingAs($borrower)->post(route('custody.acknowledge', $custody));
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.release', $custody));
        $line = $custody->lines->first();

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.return', $custody), [
            'quantities' => [$line->id => 2],
            'conditions' => [$line->id => 'STOLEN'],
        ])->assertSessionHasErrors('police_blotter_references');

        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($spmuOfficer)->post(route('custody.return', $custody), [
            'quantities' => [$line->id => 2],
            'conditions' => [$line->id => 'STOLEN'],
            'police_blotter_references' => [$line->id => 'PNP-BLOTTER-2026-001'],
            'evidence_files' => [$line->id => UploadedFile::fake()->create('incident.pdf', 10, 'application/pdf')],
            'remarks' => 'Reported to the proper authority.',
        ])->assertSessionHasNoErrors();

        $incident = Incident::where('custody_transaction_id', $custody->id)->firstOrFail();
        $this->assertNotNull($incident->supporting_evidence_file_id);
        $this->assertSame('PNP-BLOTTER-2026-001', $incident->police_blotter_reference);
        $this->assertDatabaseHas('generated_documents', ['subject_type' => Incident::class, 'subject_id' => $incident->id, 'document_type' => 'RSLDDP']);
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);

        $this->actingAs($spmu)->post(route('incidents.bill', $incident), ['amount' => 500, 'basis' => 'Authorized appraisal.'])->assertSessionHasNoErrors();
        $billing = BillingStatement::where('borrower_user_id', $borrower->id)->firstOrFail();
        $this->actingAs($spmu)->post(route('billings.waive', $billing), ['reason' => 'Authorized institutional waiver for test closeout.'])->assertSessionHasNoErrors();
        $this->assertSame('WAIVED', $billing->fresh()->status);
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertDatabaseHas('borrower_restrictions', ['incident_id' => $incident->id, 'status' => 'LIFTED']);
    }

    private function approvedDownloadedRequest(float $quantity): array
    {
        $borrower = $this->roleUser(UserRole::Borrower);
        $spmu = $this->classificationUser(AccessClassification::SpmuHead);
        $spmuOfficer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $gsu = $this->roleUser(UserRole::Gsu);
        $vpaf = $this->roleUser(UserRole::Vpaf);
        $item = InventoryItem::where('unique_description', 'Round Table')->firstOrFail();
        $request = BorrowingRequest::create(['request_no' => 'BR-REDUCE-001', 'borrower_user_id' => $borrower->id, 'accountable_unit_id' => $borrower->organizational_unit_id, 'current_version_no' => 1, 'status' => RequestStatus::Draft]);
        $version = $request->versions()->create(['version_no' => 1, 'purpose_event' => 'Workshop', 'location' => 'Auditorium', 'needed_from' => now()->addDays(2), 'return_due_at' => now()->addDays(3), 'event_details' => 'Workshop setup', 'off_campus' => false, 'created_by_user_id' => $borrower->id]);
        RequestItem::create(['request_version_id' => $version->id, 'inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description, 'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => $quantity]);
        $this->actingAs($borrower)->post(route('requests.submit', $request));
        $this->actingAs($spmu)->post(route('approvals.decide', $request), ['decision' => 'APPROVED']);
        $this->actingAs($gsu)->post(route('approvals.decide', $request), ['decision' => 'APPROVED']);
        $this->actingAs($vpaf)->post(route('approvals.decide', $request), ['decision' => 'APPROVED']);
        $letter = GeneratedDocument::where('request_version_id', $version->id)->where('document_type', 'APPROVED_REQUEST_LETTER')->where('status', 'FINAL')->firstOrFail();
        $this->actingAs($borrower)->get(route('documents.download', $letter));

        return [$borrower, $spmu, $spmuOfficer, $gsu, $vpaf, $request->fresh()];
    }

    private function roleUser(UserRole $role): User
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('role_code', $role->value)->whereNull('user_roles.revoked_at'))->firstOrFail();
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()->where('access_classification', $classification->value)->firstOrFail();
    }
}
