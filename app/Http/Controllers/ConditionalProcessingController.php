<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\SignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConditionalProcessingController extends Controller
{
    public function signGatePassVerified(Request $request, GatePass $gatePass, SignatureService $signatures, DocumentService $documents, AuditService $audit): RedirectResponse
    {
        $signed = DB::transaction(function () use ($request, $gatePass, $signatures, $documents, $audit): bool {
            $gatePass = GatePass::query()->lockForUpdate()->findOrFail($gatePass->id);
            $gatePass->loadMissing('custody.request');
            if ($request->user()->access_classification !== AccessClassification::SpmuOfficer || $gatePass->custody->borrower_user_id === $request->user()->id) {
                abort(403, 'Only a non-borrowing-on-this-request SPMU Action Officer may sign Verified By.');
            }
            if ($gatePass->prepared_verified_at) {
                return false;
            }
            if ($gatePass->status !== 'PENDING' || $gatePass->custody->status !== 'PREPARING_RELEASE') {
                throw ValidationException::withMessages(['gate_pass' => 'The Gate Pass is not in a state that allows the Verified By signature.']);
            }

            $snapshot = $signatures->snapshot($request->user(), 'GATE_PASS_VERIFIED_BY', 'SPMU_ACTION_OFFICER');
            $gatePass->update(['prepared_verified_by_user_id' => $request->user()->id, 'prepared_verifier_signature_snapshot_id' => $snapshot->id, 'prepared_verified_at' => now(), 'status' => 'AWAITING_HEAD_APPROVAL']);
            $documents->replaceConditionalForm($gatePass->custody, 'GATE_PASS');
            $audit->record('GATE_PASS_DIGITALLY_VERIFIED', $gatePass, after: ['signature_snapshot_id' => $snapshot->id]);

            return true;
        }, 3);

        return back()->with('status', $signed
            ? 'Gate Pass Verified By signature recorded. SPMU Head approval is required before printing.'
            : 'The Gate Pass Verified By signature was already recorded. No duplicate signature or document was created.');
    }

    public function signGatePassApproved(Request $request, GatePass $gatePass, SignatureService $signatures, DocumentService $documents, AuditService $audit): RedirectResponse
    {
        $signed = DB::transaction(function () use ($request, $gatePass, $signatures, $documents, $audit): bool {
            $gatePass = GatePass::query()->lockForUpdate()->findOrFail($gatePass->id);
            $gatePass->loadMissing('custody.request');
            $delegation = $request->user()->activeDelegationFor('SPMU');
            if ($request->user()->access_classification !== AccessClassification::SpmuHead && ! $delegation) {
                abort(403, 'Only the SPMU Head or a valid temporary delegated approver may sign Approved By.');
            }
            if ($gatePass->approved_at) {
                return false;
            }
            if ($gatePass->status !== 'AWAITING_HEAD_APPROVAL' || ! $gatePass->prepared_verified_at || $gatePass->prepared_verified_by_user_id === $request->user()->id || $gatePass->custody->borrower_user_id === $request->user()->id) {
                throw ValidationException::withMessages(['gate_pass' => 'A separate SPMU Action Officer must first complete Verified By, and no signer may act on their own borrowing.']);
            }
            $snapshot = $signatures->snapshot($request->user(), 'GATE_PASS_APPROVED_BY', 'SPMU_HEAD');
            $gatePass->update(['approved_by_user_id' => $request->user()->id, 'approver_signature_snapshot_id' => $snapshot->id, 'temporary_delegation_id' => $delegation?->id, 'approved_at' => now(), 'status' => 'READY_FOR_PRINTING']);
            $documents->replaceConditionalForm($gatePass->custody, 'GATE_PASS');
            $audit->record('GATE_PASS_DIGITALLY_APPROVED', $gatePass, after: ['signature_snapshot_id' => $snapshot->id, 'temporary_delegation_id' => $delegation?->id]);

            return true;
        }, 3);

        return back()->with('status', $signed
            ? 'Gate Pass digitally approved and ready for printing. The guard signs after campus exit.'
            : 'The Gate Pass approval was already recorded. No duplicate signature or document was created.');
    }

    public function approveLaundryForm(Request $request, CustodyTransaction $custody, SignatureService $signatures, DocumentService $documents, AuditService $audit): RedirectResponse
    {
        $signed = DB::transaction(function () use ($request, $custody, $signatures, $documents, $audit): bool {
            $custody = CustodyTransaction::query()->lockForUpdate()->findOrFail($custody->id);
            $custody->loadMissing('lines.requestItem.inventoryItem');
            abort_unless($custody->lines->contains(fn ($line) => $line->requestItem->inventoryItem->laundry_required), 404);
            $delegation = $request->user()->activeDelegationFor('SPMU');
            if ($request->user()->access_classification !== AccessClassification::SpmuHead && ! $delegation) {
                abort(403, 'Only the SPMU Head or a valid temporary delegated approver may approve the Laundry Form.');
            }
            if ($custody->laundry_approved_at) {
                return false;
            }
            if ($custody->status !== 'PREPARING_RELEASE' || ! $custody->laundry_borrower_signature_snapshot_id || $custody->borrower_user_id === $request->user()->id) {
                throw ValidationException::withMessages(['laundry' => 'The borrower must first sign the receipt acknowledgement and Laundry Form.']);
            }
            $snapshot = $signatures->snapshot($request->user(), 'LAUNDRY_FORM_APPROVED_BY', 'SPMU_HEAD');
            $custody->update(['laundry_approved_by_user_id' => $request->user()->id, 'laundry_approver_signature_snapshot_id' => $snapshot->id, 'laundry_temporary_delegation_id' => $delegation?->id, 'laundry_approved_at' => now()]);
            $documents->replaceConditionalForm($custody, 'LAUNDRY_FORM');
            $audit->record('LAUNDRY_FORM_DIGITALLY_APPROVED', $custody, after: ['signature_snapshot_id' => $snapshot->id, 'temporary_delegation_id' => $delegation?->id]);

            return true;
        }, 3);

        return back()->with('status', $signed
            ? 'Laundry Form contains the Borrower and SPMU Head digital signatures and is ready for printing.'
            : 'The Laundry Form approval was already recorded. No duplicate signature or document was created.');
    }

    public function gatePass(Request $request, GatePass $gatePass, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'guard_name' => ['required', 'string', 'max:255'],
            'guard_signed_at' => ['required', 'date'],
        ]);
        $verified = DB::transaction(function () use ($request, $gatePass, $audit, $data): bool {
            $gatePass = GatePass::query()->lockForUpdate()->findOrFail($gatePass->id);
            $gatePass->loadMissing('custody');
            if ($gatePass->status === 'VERIFIED') {
                return false;
            }
            if ($gatePass->status !== 'READY_FOR_PRINTING' || ! $gatePass->approved_at || ! $gatePass->custody->released_at) {
                throw ValidationException::withMessages(['gate_pass' => 'The current approved Gate Pass can be physically verified only after item release.']);
            }
            $verifiedEvidence = $gatePass->pass_document_id
                && DB::table('evidence_submissions')->where('generated_document_id', $gatePass->pass_document_id)->where('verification_status', 'VERIFIED')->exists();
            if (! $verifiedEvidence) {
                throw ValidationException::withMessages(['gate_pass' => 'Verify the uploaded signed Gate Pass evidence before completing the paper-form record.']);
            }
            $gatePass->update([
                'guard_name' => $data['guard_name'],
                'guard_signed_at' => $data['guard_signed_at'],
                'verified_by_user_id' => $request->user()->id,
                'verified_at' => now(),
                'status' => 'VERIFIED',
            ]);
            $gatePass->custody->lines()->whereHas('requestItem', fn ($query) => $query->where('use_location', 'OFF_CAMPUS'))->update(['compliance_status' => 'CLEARED_GATE']);
            $audit->record('GATE_PASS_PHYSICAL_VERIFICATION', $gatePass, after: $data);

            return true;
        }, 3);

        return back()->with('status', $verified
            ? 'Gate Pass paper signature and physical event verified.'
            : 'The Gate Pass physical verification was already completed. No duplicate action was recorded.');
    }

    public function laundry(Request $request, LaundryRecord $laundry, AuditService $audit, DocumentService $documents): RedirectResponse
    {
        $laundry->loadMissing('returnLine.custodyLine.requestItem.inventoryItem', 'returnLine.custodyLine.custody');
        $data = $request->validate([
            'worker_name' => ['required', 'string', 'max:255'],
            'worker_received_at' => ['required', 'date'],
            'worker_completed_at' => ['required', 'date', 'after_or_equal:worker_received_at'],
            'cleaned_quantity' => ['required', 'numeric', 'min:0'],
            'damaged_quantity' => ['required', 'numeric', 'min:0'],
        ]);
        $documentId = $laundry->form_document_id ?: DB::table('generated_documents')
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $laundry->returnLine->custodyLine->custody_transaction_id)
            ->where('document_type', 'LAUNDRY_FORM')->where('status', 'FINAL')->value('id');
        $verifiedEvidence = $documentId && DB::table('evidence_submissions')->where('generated_document_id', $documentId)->where('verification_status', 'VERIFIED')->exists();
        if (! $verifiedEvidence) {
            throw ValidationException::withMessages(['laundry' => 'Verify the uploaded signed Laundry Form evidence before final physical inspection.']);
        }
        $expected = (float) $laundry->returnLine->quantity_received;
        if (abs(((float) $data['cleaned_quantity'] + (float) $data['damaged_quantity']) - $expected) > 0.0001) {
            throw ValidationException::withMessages(['cleaned_quantity' => "Cleaned plus damaged quantities must equal the returned linen quantity of {$expected}."]);
        }

        $verified = DB::transaction(function () use ($laundry, $request, $data, $documentId, $audit, $documents): bool {
            $laundry = LaundryRecord::query()->lockForUpdate()->findOrFail($laundry->id);
            $laundry->loadMissing('returnLine.custodyLine.requestItem.inventoryItem', 'returnLine.custodyLine.custody');
            if ($laundry->status === 'VERIFIED') {
                return false;
            }
            if ($laundry->status !== 'EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK') {
                throw ValidationException::withMessages(['laundry' => 'Laundry completion requires verified evidence for the current form before physical inspection.']);
            }
            $itemId = $laundry->returnLine->custodyLine->requestItem->inventory_item_id;
            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $request->user()->id,
                'transaction_type' => 'LAUNDRY_COMPLETION',
                'source_type' => LaundryRecord::class,
                'source_id' => $laundry->id,
                'reason' => 'Signed form evidence and physical linen condition independently verified.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([['AVAILABLE', (float) $data['cleaned_quantity']], ['DAMAGED_MAINTENANCE', (float) $data['damaged_quantity']]] as [$state, $quantity]) {
                if ($quantity <= 0) {
                    continue;
                }
                DB::table('inventory_transaction_lines')->insert([
                    'inventory_transaction_id' => $transactionId,
                    'inventory_item_id' => $itemId,
                    'from_state' => 'LAUNDRY',
                    'to_state' => $state,
                    'quantity' => $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $laundry->update([
                'form_document_id' => $documentId,
                'verified_by_user_id' => $request->user()->id,
                'worker_name' => $data['worker_name'],
                'worker_received_at' => $data['worker_received_at'],
                'worker_completed_at' => $data['worker_completed_at'],
                'cleaned_quantity' => $data['cleaned_quantity'],
                'damaged_quantity' => $data['damaged_quantity'],
                'status' => 'VERIFIED',
                'verified_at' => now(),
            ]);
            $laundry->returnLine->custodyLine->update(['item_status' => (float) $data['damaged_quantity'] > 0 ? 'INCIDENT_PENDING' : 'RETURNED', 'compliance_status' => 'LAUNDRY_COMPLETED']);
            $custody = $laundry->returnLine->custodyLine->custody;
            if ((float) $data['damaged_quantity'] > 0) {
                $incident = Incident::query()->create([
                    'incident_no' => 'INC-LAUNDRY-'.now()->format('YmdHis').'-'.$laundry->id,
                    'custody_transaction_id' => $custody->id,
                    'borrower_user_id' => $custody->borrower_user_id,
                    'reported_by_user_id' => $request->user()->id,
                    'incident_type' => 'DAMAGED',
                    'reported_at' => now(),
                    'status' => 'OPEN',
                    'remarks' => 'Damage confirmed during final laundry inspection.',
                ]);
                IncidentLine::query()->create([
                    'incident_id' => $incident->id,
                    'custody_line_id' => $laundry->returnLine->custody_line_id,
                    'quantity' => $data['damaged_quantity'],
                    'observed_condition' => 'DAMAGED_AFTER_LAUNDRY',
                    'disposition_state' => 'DAMAGED_MAINTENANCE',
                ]);
                BorrowerRestriction::query()->firstOrCreate([
                    'borrower_user_id' => $custody->borrower_user_id,
                    'incident_id' => $incident->id,
                    'status' => 'ACTIVE',
                ], [
                    'restriction_type' => 'UNRESOLVED_INCIDENT',
                    'reason' => 'Unresolved laundry damage incident '.$incident->incident_no.'.',
                    'effective_from' => now(),
                    'imposed_by_user_id' => $request->user()->id,
                ]);
                if (SystemSetting::value('rslddp_template_status') === 'APPROVED') {
                    $documents->rslddp($incident->fresh());
                }
            }
            $openLaundry = LaundryRecord::query()->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custody->id))->whereNot('status', 'VERIFIED')->exists();
            $openIncident = DB::table('incidents')->where('custody_transaction_id', $custody->id)->whereNotIn('status', ['RESOLVED', 'CLOSED'])->exists();
            $openOverdue = OverdueCase::query()->where('custody_transaction_id', $custody->id)->whereNot('status', 'RESOLVED')->exists();
            $openGatePass = $custody->gatePass()->whereNot('status', 'VERIFIED')->exists();
            $allReturned = $custody->lines()->get()->every(fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity);
            if (! $openLaundry && ! $openIncident && ! $openOverdue && ! $openGatePass && $allReturned) {
                $custody->update(['status' => 'CLOSED', 'closed_at' => now()]);
            } elseif ($allReturned) {
                $custody->update(['status' => 'OBLIGATION_OPEN']);
            }
            $audit->record('LAUNDRY_PHYSICAL_VERIFICATION', $laundry, after: $data);

            return true;
        }, 3);

        return back()->with('status', $verified
            ? 'Laundry evidence and physical condition verified. Clean linen returned to Available.'
            : 'Laundry completion was already verified. No duplicate ledger entry or incident was created.');
    }
}
