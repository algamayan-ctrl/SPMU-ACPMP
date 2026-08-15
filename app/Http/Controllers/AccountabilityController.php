<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountabilityController extends Controller
{
    public function index(Request $request): View
    {
        $incidentQuery = Incident::with(['borrower', 'custody.request', 'lines'])->latest('reported_at');
        $billingQuery = BillingStatement::with(['borrower', 'lines', 'payments', 'documents'])->latest('issued_at');
        $restrictionQuery = BorrowerRestriction::latest('effective_from');
        $overdueQuery = OverdueCase::with(['borrower', 'custody.lines', 'penalties'])->latest('overdue_started_at');
        if ($request->session()->get('active_workspace') === 'BORROWER') {
            $incidentQuery->where('borrower_user_id', $request->user()->id);
            $billingQuery->where('borrower_user_id', $request->user()->id);
            $restrictionQuery->where('borrower_user_id', $request->user()->id);
            $overdueQuery->where('borrower_user_id', $request->user()->id);
        }

        return view('accountability.index', [
            'incidents' => $incidentQuery->get(),
            'billings' => $billingQuery->get(),
            'restrictions' => $restrictionQuery->get(),
            'overdueCases' => $overdueQuery->get(),
        ]);
    }

    public function billOverdue(Request $request, OverdueCase $overdue, DocumentService $documents, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['basis' => ['required', 'string', 'max:2000'], 'due_at' => ['nullable', 'date', 'after_or_equal:today']]);
        $overdue->loadMissing('custody.lines');
        if (! $overdue->custody->lines->every(fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity)) {
            return back()->withErrors(['overdue' => 'The overdue items must first receive a physical return disposition so the final overdue duration is known.']);
        }
        if ((float) $overdue->accrued_amount <= 0 || $overdue->rate_snapshot === null) {
            return back()->withErrors(['overdue' => 'Configure the approved daily overdue tariff and run deadline processing before issuing this Billing Statement.']);
        }
        if ($overdue->penalties()->whereNot('status', 'VOID')->exists()) {
            return back()->withErrors(['overdue' => 'This overdue case already has an assessed penalty.']);
        }

        $billing = DB::transaction(function () use ($overdue, $request, $data, $documents, $audit): BillingStatement {
            $penalty = Penalty::query()->create([
                'borrower_user_id' => $overdue->borrower_user_id,
                'custody_transaction_id' => $overdue->custody_transaction_id,
                'overdue_case_id' => $overdue->id,
                'assessed_by_user_id' => $request->user()->id,
                'penalty_type' => 'OVERDUE',
                'offense_level' => $overdue->offense_level,
                'basis' => $data['basis'],
                'rate_snapshot' => $overdue->rate_snapshot,
                'amount' => $overdue->accrued_amount,
                'status' => 'ASSESSED',
                'assessed_at' => now(),
            ]);
            $billing = BillingStatement::query()->create([
                'billing_no' => 'BILL-OVERDUE-'.now()->format('YmdHis').'-'.$overdue->id,
                'borrower_user_id' => $overdue->borrower_user_id,
                'responsible_spmu_user_id' => $request->user()->id,
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'total_amount' => $penalty->amount,
                'status' => 'ISSUED',
                'remarks' => 'Overdue penalty for '.$overdue->custody->custody_no,
            ]);
            $billing->lines()->create([
                'penalty_id' => $penalty->id,
                'line_type' => 'PENALTY',
                'description' => 'Overdue return penalty, offense level '.$overdue->offense_level,
                'basis' => $data['basis'],
                'amount' => $penalty->amount,
            ]);
            BorrowerRestriction::query()->where('borrower_user_id', $overdue->borrower_user_id)
                ->where('restriction_type', 'OVERDUE_RETURN')->where('status', 'ACTIVE')
                ->update(['penalty_id' => $penalty->id, 'billing_statement_id' => $billing->id]);
            $overdue->update(['status' => 'BILLED']);
            $documents->billingStatement($billing);
            $audit->record('OVERDUE_PENALTY_BILLED', $billing, reason: $data['basis'], after: ['amount' => $penalty->amount, 'rate' => $penalty->rate_snapshot]);

            return $billing;
        });

        return back()->with('status', "Billing statement {$billing->billing_no} generated from the configured overdue tariff.");
    }

    public function billIncident(Request $request, Incident $incident, DocumentService $documents, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'basis' => ['required', 'string', 'max:2000'], 'due_at' => ['nullable', 'date', 'after_or_equal:today']]);
        if (DB::table('billing_lines')->where('incident_id', $incident->id)->exists()) {
            return back()->withErrors(['incident' => 'This incident already has a Billing Statement.']);
        }
        $billing = DB::transaction(function () use ($incident, $request, $data, $documents, $audit): BillingStatement {
            $billing = BillingStatement::query()->create([
                'billing_no' => 'BILL-'.now()->format('YmdHis').'-'.$incident->id,
                'borrower_user_id' => $incident->borrower_user_id,
                'responsible_spmu_user_id' => $request->user()->id,
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'total_amount' => $data['amount'],
                'status' => 'ISSUED',
                'remarks' => 'Property charge linked to '.$incident->incident_no,
            ]);
            $billing->lines()->create([
                'incident_id' => $incident->id,
                'line_type' => 'PROPERTY_CHARGE',
                'description' => $incident->incident_type.' property charge',
                'basis' => $data['basis'],
                'amount' => $data['amount'],
            ]);
            $incident->update(['appraisal_amount' => $data['amount'], 'status' => 'BILLING_PENDING']);
            BorrowerRestriction::query()->updateOrCreate([
                'borrower_user_id' => $incident->borrower_user_id,
                'incident_id' => $incident->id,
                'status' => 'ACTIVE',
            ], [
                'restriction_type' => 'UNRESOLVED_PROPERTY_OBLIGATION',
                'reason' => 'Open billing statement '.$billing->billing_no,
                'effective_from' => now(),
                'imposed_by_user_id' => $request->user()->id,
                'billing_statement_id' => $billing->id,
            ]);
            $documents->billingStatement($billing);
            $audit->record('BILLING_STATEMENT_ISSUED', $billing, reason: $data['basis'], after: ['amount' => $data['amount'], 'source' => $incident->incident_no]);

            return $billing;
        });

        return back()->with('status', "Billing statement {$billing->billing_no} generated for a property charge only.");
    }

    public function recordPayment(Request $request, BillingStatement $billing, ProtectedFileService $files, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless($billing->borrower_user_id === $request->user()->id && ! in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true), 403);
        $data = $request->validate([
            'evidence' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:5120'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $file = $files->storeUpload($data['evidence'], 'payment-evidence', 'PAYMENT_EVIDENCE');
        $payment = Payment::query()->create([
            'billing_statement_id' => $billing->id,
            'evidence_file_id' => $file->id,
            'recorded_by_user_id' => $request->user()->id,
            'status' => 'SUBMITTED_PENDING_ORIGINAL',
            'submitted_at' => now(),
            'verification_remarks' => $data['remarks'] ?? null,
        ]);
        $billing->update(['status' => 'RECEIPT_SUBMITTED']);
        $audit->record('PAYMENT_RECEIPT_SUBMITTED_BY_BORROWER', $payment, after: ['evidence_file_id' => $file->id]);
        $notifications->send('PAYMENT_RECEIPT_SUBMITTED', $this->spmuOfficers(), "Receipt evidence for {$billing->billing_no} was uploaded. Inspect the original and encode the Official Receipt details.", $billing);

        return back()->with('status', 'Receipt copy uploaded. Bring the original to SPMU for inspection and Official Receipt encoding.');
    }

    public function verifyPayment(Request $request, Payment $payment, AuditService $audit, NotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()->access_classification === AccessClassification::SpmuOfficer, 403);
        $data = $request->validate([
            'decision' => ['required', 'in:VERIFIED,REJECTED'],
            'official_receipt_no' => ['required_if:decision,VERIFIED', 'nullable', 'string', 'max:255'],
            'receipt_date' => ['required_if:decision,VERIFIED', 'nullable', 'date'],
            'amount' => ['required_if:decision,VERIFIED', 'nullable', 'numeric', 'min:0.01'],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);
        $payment->loadMissing('billingStatement');
        DB::transaction(function () use ($payment, $request, $audit, $notifications, $data): void {
            $billing = BillingStatement::findOrFail($payment->billing_statement_id);
            if ($data['decision'] === 'REJECTED') {
                $payment->update(['verified_by_user_id' => $request->user()->id, 'status' => 'REJECTED', 'verified_at' => now(), 'rejection_reason' => $data['remarks']]);
                $billing->update(['status' => 'ISSUED']);
                $audit->record('PAYMENT_RECEIPT_REJECTED', $payment, reason: $data['remarks']);
                $notifications->send('PAYMENT_RECEIPT_REJECTED', collect([$billing->borrower]), "Receipt evidence for {$billing->billing_no} was rejected: {$data['remarks']}", $billing);

                return;
            }
            $payment->update([
                'verified_by_user_id' => $request->user()->id, 'official_receipt_no' => $data['official_receipt_no'],
                'receipt_date' => $data['receipt_date'], 'amount' => $data['amount'], 'status' => 'VERIFIED',
                'verified_at' => now(), 'verification_remarks' => $data['remarks'], 'rejection_reason' => null,
            ]);
            $verified = (float) $billing->payments()->where('status', 'VERIFIED')->sum('amount');
            if ($verified >= (float) $billing->total_amount) {
                $billing->update(['status' => 'SETTLED']);
                BorrowerRestriction::query()->where('billing_statement_id', $billing->id)->where('status', 'ACTIVE')->update([
                    'status' => 'LIFTED', 'effective_to' => now(), 'lifted_by_user_id' => $request->user()->id,
                ]);
                $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
                Incident::query()->whereKey($incidentIds)->where('status', 'BILLING_PENDING')->update(['status' => 'RESOLVED']);
                $penaltyIds = $billing->lines()->whereNotNull('penalty_id')->pluck('penalty_id');
                Penalty::query()->whereKey($penaltyIds)->update(['status' => 'SETTLED']);
                OverdueCase::query()->whereHas('penalties', fn ($query) => $query->whereIn('penalties.id', $penaltyIds))->update(['status' => 'RESOLVED']);
                $custodyIds = Incident::query()->whereKey($incidentIds)->pluck('custody_transaction_id')
                    ->merge(Penalty::query()->whereKey($penaltyIds)->pluck('custody_transaction_id'))->unique();
                foreach ($custodyIds as $custodyId) {
                    $this->attemptCloseCustody((int) $custodyId);
                }
            }
            $audit->record('EXTERNAL_PAYMENT_VERIFIED', $payment, after: ['billing_status' => $billing->fresh()->status]);
            $notifications->send('PAYMENT_RECEIPT_VERIFIED', collect([$billing->borrower]), "Official Receipt {$payment->official_receipt_no} for {$billing->billing_no} was verified by SPMU.", $billing);
        });

        return back()->with('status', $data['decision'] === 'VERIFIED' ? 'Original receipt inspected and Official Receipt details verified.' : 'Receipt submission rejected with an attributable reason.');
    }

    public function waive(Request $request, BillingStatement $billing, AuditService $audit): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        if (in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true)) {
            return back()->withErrors(['billing' => 'This Billing Statement already has a final status.']);
        }
        DB::transaction(function () use ($billing, $request, $data, $audit): void {
            $billing->update(['status' => 'WAIVED', 'remarks' => trim(($billing->remarks ? $billing->remarks."\n" : '').'Authorized waiver: '.$data['reason'])]);
            BorrowerRestriction::query()->where('billing_statement_id', $billing->id)->where('status', 'ACTIVE')->update([
                'status' => 'LIFTED', 'effective_to' => now(), 'lifted_by_user_id' => $request->user()->id,
            ]);
            $incidentIds = $billing->lines()->whereNotNull('incident_id')->pluck('incident_id');
            $penaltyIds = $billing->lines()->whereNotNull('penalty_id')->pluck('penalty_id');
            Incident::query()->whereKey($incidentIds)->update(['status' => 'RESOLVED']);
            Penalty::query()->whereKey($penaltyIds)->update(['status' => 'WAIVED']);
            OverdueCase::query()->whereHas('penalties', fn ($query) => $query->whereIn('penalties.id', $penaltyIds))->update(['status' => 'RESOLVED']);
            $custodyIds = Incident::query()->whereKey($incidentIds)->pluck('custody_transaction_id')
                ->merge(Penalty::query()->whereKey($penaltyIds)->pluck('custody_transaction_id'))->unique();
            foreach ($custodyIds as $custodyId) {
                $this->attemptCloseCustody((int) $custodyId);
            }
            $audit->record('BILLING_STATEMENT_WAIVED', $billing, reason: $data['reason']);
        });

        return back()->with('status', 'Authorized waiver recorded, linked restriction lifted, and closeout rechecked.');
    }

    private function attemptCloseCustody(int $custodyId): void
    {
        $custody = CustodyTransaction::query()->with('lines')->find($custodyId);
        if (! $custody || ! $custody->lines->every(fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity)) {
            return;
        }
        $openIncident = Incident::query()->where('custody_transaction_id', $custodyId)->whereNotIn('status', ['RESOLVED', 'CLOSED'])->exists();
        $openLaundry = LaundryRecord::query()->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custodyId))->whereNot('status', 'VERIFIED')->exists();
        $openOverdue = OverdueCase::query()->where('custody_transaction_id', $custodyId)->whereNot('status', 'RESOLVED')->exists();
        $openGatePass = $custody->gatePass()->whereNot('status', 'VERIFIED')->exists();
        if (! $openIncident && ! $openLaundry && ! $openOverdue && ! $openGatePass) {
            $custody->update(['status' => 'CLOSED', 'closed_at' => now()]);
        }
    }

    private function spmuOfficers(): Collection
    {
        return User::query()->where('access_classification', AccessClassification::SpmuOfficer->value)->where('account_status', 'ACTIVE')->get();
    }
}
