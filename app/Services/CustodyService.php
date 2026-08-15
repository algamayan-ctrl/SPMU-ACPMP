<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\EarlyReturnRequest;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustodyService
{
    public function __construct(
        private SignatureService $signatures,
        private DocumentService $documents,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    public function updateReceiptQuantities(CustodyTransaction $custody, User $spmu, array $quantities, array $reasons): void
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id && $custody->status === 'PREPARING_RELEASE' && ! $custody->acknowledged_at, 403);

        DB::transaction(function () use ($custody, $quantities, $reasons): void {
            foreach ($custody->lines()->get() as $line) {
                $quantity = (float) ($quantities[$line->id] ?? $line->approved_quantity);
                if ($quantity < 0 || $quantity > (float) $line->approved_quantity) {
                    throw ValidationException::withMessages(['quantities' => 'Quantity to receive must be between zero and the approved quantity.']);
                }
                $reason = trim((string) ($reasons[$line->id] ?? ''));
                if ($quantity < (float) $line->approved_quantity && $reason === '') {
                    throw ValidationException::withMessages(['quantities' => 'Provide a reason for every reduced release quantity.']);
                }
                $line->update(['quantity_to_receive' => $quantity, 'adjustment_reason' => $reason ?: null]);
            }
            $custody->update(['prepared_at' => null, 'prepared_by_user_id' => null]);
            $this->audit->record('FINAL_ISSUED_QUANTITY_RECORDED', $custody, after: ['quantities' => $quantities, 'reasons' => $reasons]);
        });
    }

    public function prepare(CustodyTransaction $custody, User $spmu): void
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id && $custody->status === 'PREPARING_RELEASE', 403);
        $custody->loadMissing('lines');
        foreach ($custody->lines as $line) {
            if ((float) $line->quantity_to_receive < (float) $line->approved_quantity && blank($line->adjustment_reason)) {
                throw ValidationException::withMessages(['prepare' => 'Every lower quantity requires a verified adjustment reason.']);
            }
        }
        $custody->update(['prepared_by_user_id' => $spmu->id, 'prepared_at' => now()]);
        $this->audit->record('RELEASE_PREPARED', $custody, reason: 'SPMU verified physical preparation and any lower quantities.');
    }

    public function acknowledge(CustodyTransaction $custody, User $borrower): void
    {
        abort_unless($custody->borrower_user_id === $borrower->id && $custody->status === 'PREPARING_RELEASE', 403);
        if (! $custody->prepared_at) {
            throw ValidationException::withMessages(['acknowledge' => 'SPMU must verify the prepared quantities before borrower acknowledgement.']);
        }
        DB::transaction(function () use ($custody, $borrower): void {
            $snapshot = $this->signatures->snapshot($borrower, 'BORROWER_SLIP_ACKNOWLEDGEMENT', UserRole::Borrower->value);
            $hasLinen = $custody->lines()->whereHas('requestItem.inventoryItem', fn ($query) => $query->where('laundry_required', true))->exists();
            $laundrySnapshot = $hasLinen ? $this->signatures->snapshot($borrower, 'LAUNDRY_FORM_BORROWER', UserRole::Borrower->value) : null;
            $custody->update(['borrower_ack_signature_snapshot_id' => $snapshot->id, 'laundry_borrower_signature_snapshot_id' => $laundrySnapshot?->id, 'acknowledged_at' => now()]);
            $custody->lines()->update(['item_status' => 'PREPARED']);
            GeneratedDocument::query()->where('subject_type', CustodyTransaction::class)->where('subject_id', $custody->id)->where('document_type', 'BORROWER_SLIP')->where('status', 'FINAL')->update([
                'status' => 'SUPERSEDED', 'invalidated_at' => now(), 'invalidation_reason' => 'Replaced by acknowledged slip version.',
            ]);
            $this->documents->borrowerSlip($custody->fresh());
            if ($hasLinen) {
                $this->documents->replaceConditionalForm($custody->fresh(), 'LAUNDRY_FORM');
            } else {
                $this->documents->refreshPacketIfReady($custody->fresh());
            }
            $this->audit->record('BORROWER_RECEIPT_ACKNOWLEDGED', $custody, after: ['signature_snapshot_id' => $snapshot->id]);
        });
    }

    public function release(CustodyTransaction $custody, User $spmu): void
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id && $custody->status === 'PREPARING_RELEASE', 403);
        if (! $custody->prepared_at || ! $custody->acknowledged_at) {
            throw ValidationException::withMessages(['release' => 'SPMU preparation and borrower acknowledgement are required before physical release.']);
        }
        $custody->loadMissing('request.currentVersion', 'gatePass', 'lines.requestItem.inventoryItem');
        if ($custody->lines->contains(fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS')
            && (! $custody->gatePass?->prepared_verified_at || ! $custody->gatePass?->approved_at)) {
            throw ValidationException::withMessages(['release' => 'The Gate Pass must first contain the SPMU Action Officer and SPMU Head digital signatures. Guard evidence is uploaded after campus exit.']);
        }
        if ($custody->lines->contains(fn ($line) => $line->requestItem->inventoryItem->laundry_required) && ! $custody->laundry_approved_at) {
            throw ValidationException::withMessages(['release' => 'The Laundry Form must first contain the Borrower and SPMU Head digital signatures.']);
        }

        DB::transaction(function () use ($custody, $spmu): void {
            $custody->loadMissing('lines.requestItem.inventoryItem', 'lines.allocation', 'borrower', 'request');
            $transactionId = $this->transactionHeader('PHYSICAL_RELEASE', $custody, $spmu, 'Physical count, condition, and borrower acknowledgement completed.');
            foreach ($custody->lines as $line) {
                $allocation = $line->allocation()->lockForUpdate()->firstOrFail();
                $actual = (float) $line->quantity_to_receive;
                $unused = max(0, (float) $line->approved_quantity - $actual);
                $line->update(['actual_released_quantity' => $actual, 'release_condition' => 'SERVICEABLE']);
                $allocation->update([
                    'released_quantity' => $actual,
                    'restored_quantity' => (float) $allocation->restored_quantity + $unused,
                    'status' => $actual > 0 ? 'RELEASED' : 'RESTORED',
                ]);
                if ($actual > 0) {
                    $this->transactionLine($transactionId, $line->requestItem->inventory_item_id, 'ALLOCATED', 'BORROWED', $actual, $allocation->period_start, $allocation->period_end);
                }
                if ($unused > 0) {
                    $this->transactionLine($transactionId, $line->requestItem->inventory_item_id, 'ALLOCATED', 'AVAILABLE', $unused, $allocation->period_start, $allocation->period_end);
                }
                $line->update(['item_status' => $actual > 0 ? 'RELEASED_PENDING_RETURN' : 'CLOSED', 'compliance_status' => $line->requestItem->use_location === 'OFF_CAMPUS' ? 'AWAITING_GUARD_SIGNATURE' : ($line->requestItem->inventoryItem->laundry_required ? 'LAUNDRY_FORM_READY' : 'NOT_REQUIRED')]);
            }
            $custody->update(['released_by_user_id' => $spmu->id, 'released_at' => now(), 'status' => 'ACTIVE']);
            $this->audit->record('ITEMS_RELEASED', $custody, after: ['released_by' => $spmu->id, 'released_at' => now()->toIso8601String()]);
            $this->notifications->send('ITEMS_RELEASED', collect([$custody->borrower]), "Items under {$custody->custody_no} were physically released. Return deadline: {$custody->due_at->format('F j, Y g:i A')}.", $custody);
        }, 3);
    }

    public function receiveReturn(CustodyTransaction $custody, User $spmu, array $quantities, array $conditions, ?string $remarks, bool $early = false, array $policeBlotterReferences = [], array $evidenceFileIds = []): ReturnTransaction
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id, 403);

        return DB::transaction(function () use ($custody, $spmu, $quantities, $conditions, $remarks, $early, $policeBlotterReferences, $evidenceFileIds): ReturnTransaction {
            $custody = CustodyTransaction::query()->lockForUpdate()->findOrFail($custody->id);
            if (! in_array($custody->status, ['ACTIVE', 'PARTIALLY_RETURNED', 'OVERDUE', 'EARLY_RETURN', 'INCIDENT_OPEN'], true)) {
                throw ValidationException::withMessages(['return' => 'This custody record is no longer open for a physical return.']);
            }

            $custody->setRelation('lines', $custody->lines()->with('requestItem.inventoryItem')->lockForUpdate()->get());
            $custody->loadMissing('borrower');
            $selectedLines = $custody->lines->filter(fn ($line) => (float) ($quantities[$line->id] ?? 0) > 0);
            if ($selectedLines->isEmpty()) {
                throw ValidationException::withMessages(['return' => 'Enter a returned quantity greater than zero for at least one item.']);
            }

            $return = ReturnTransaction::query()->create([
                'return_no' => 'RET-'.now()->format('YmdHis').'-'.$custody->id,
                'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $spmu->id,
                'return_type' => $early ? 'EARLY' : 'NORMAL',
                'received_at' => now(),
                'status' => 'INSPECTED',
                'remarks' => $remarks,
            ]);
            $transactionId = $this->transactionHeader('PHYSICAL_RETURN', $return, $spmu, $remarks ?: 'Physical return and manual condition inspection.');

            foreach ($custody->lines as $line) {
                $outstanding = max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity);
                $quantity = (float) ($quantities[$line->id] ?? 0);
                $condition = strtoupper((string) ($conditions[$line->id] ?? 'FINE'));
                $blotterReference = trim((string) ($policeBlotterReferences[$line->id] ?? ''));
                if ($quantity < 0 || $quantity > $outstanding) {
                    throw ValidationException::withMessages(['return' => 'Returned quantity cannot exceed the outstanding borrowed quantity.']);
                }
                if ($quantity <= 0) {
                    continue;
                }
                if ($condition === 'STOLEN' && $blotterReference === '') {
                    throw ValidationException::withMessages(['police_blotter_references' => 'A police-blotter reference is required for every stolen quantity.']);
                }
                if ($condition !== 'FINE' && empty($evidenceFileIds[$line->id])) {
                    throw ValidationException::withMessages(['evidence_files' => 'Supporting evidence is required for every damaged, destroyed, missing, lost, or stolen quantity.']);
                }

                $item = $line->requestItem->inventoryItem;
                $disposition = $condition === 'FINE' ? ($item->laundry_required ? 'LAUNDRY' : 'AVAILABLE') : match ($condition) {
                    'MISSING', 'LOST' => 'LOST',
                    'STOLEN' => 'STOLEN',
                    'DESTROYED' => 'DESTROYED',
                    default => 'DAMAGED_MAINTENANCE',
                };
                $returnLine = ReturnLine::query()->create([
                    'return_transaction_id' => $return->id,
                    'custody_line_id' => $line->id,
                    'quantity_received' => $quantity,
                    'condition_code' => $condition,
                    'disposition_state' => $disposition,
                    'remarks' => $remarks,
                ]);
                $line->increment('returned_quantity', $quantity);
                $this->transactionLine($transactionId, $item->id, 'BORROWED', $disposition, $quantity);

                if ($disposition === 'LAUNDRY') {
                    LaundryRecord::query()->create(['return_line_id' => $returnLine->id, 'cleaned_quantity' => 0, 'damaged_quantity' => 0, 'status' => 'PENDING_EVIDENCE']);
                    $line->update(['item_status' => 'IN_LAUNDRY', 'compliance_status' => 'LAUNDRY_FORM_PENDING']);
                } elseif ($condition !== 'FINE') {
                    $line->update(['item_status' => 'INCIDENT_PENDING']);
                    $incident = Incident::query()->create([
                        'incident_no' => 'INC-'.now()->format('YmdHis').'-'.$returnLine->id,
                        'custody_transaction_id' => $custody->id,
                        'borrower_user_id' => $custody->borrower_user_id,
                        'reported_by_user_id' => $spmu->id,
                        'supporting_evidence_file_id' => $evidenceFileIds[$line->id] ?? null,
                        'incident_type' => $condition,
                        'reported_at' => now(),
                        'police_blotter_reference' => $blotterReference ?: null,
                        'status' => 'OPEN',
                        'remarks' => $remarks,
                    ]);
                    IncidentLine::query()->create([
                        'incident_id' => $incident->id,
                        'custody_line_id' => $line->id,
                        'quantity' => $quantity,
                        'observed_condition' => $condition,
                        'disposition_state' => $disposition,
                    ]);
                    BorrowerRestriction::query()->firstOrCreate([
                        'borrower_user_id' => $custody->borrower_user_id,
                        'incident_id' => $incident->id,
                        'status' => 'ACTIVE',
                    ], [
                        'restriction_type' => 'UNRESOLVED_INCIDENT',
                        'reason' => 'Unresolved '.$condition.' incident '.$incident->incident_no.'.',
                        'effective_from' => now(),
                        'imposed_by_user_id' => $spmu->id,
                    ]);
                    if (SystemSetting::value('rslddp_template_status') === 'APPROVED') {
                        $this->documents->rslddp($incident->fresh());
                    }
                }
                if ($condition === 'FINE' && $disposition === 'AVAILABLE') {
                    $remaining = max(0, (float) $line->actual_released_quantity - (float) $line->fresh()->returned_quantity);
                    $line->update(['item_status' => $remaining > 0 ? 'PARTIALLY_RETURNED' : 'RETURNED']);
                }
            }

            $custody->refresh()->load('lines');
            $allReturned = $custody->lines->every(fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity);
            $overdue = OverdueCase::query()->where('custody_transaction_id', $custody->id)->first();
            if ($overdue && $allReturned && $overdue->status !== 'RESOLVED') {
                $rate = SystemSetting::value('daily_overdue_tariff');
                $days = max(1, (int) ceil($overdue->grace_expires_at->diffInMinutes(now()) / 1440));
                $overdue->update([
                    'rate_snapshot' => is_numeric($rate) ? $rate : null,
                    'accrued_amount' => is_numeric($rate) ? round($days * (float) $rate, 2) : 0,
                    'status' => 'RETURNED_PENDING_SETTLEMENT',
                ]);
            }
            $hasOpenIncident = Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
                ->exists();
            $hasOpenLaundry = LaundryRecord::query()
                ->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custody->id))
                ->whereNot('status', 'VERIFIED')
                ->exists();
            $hasOpenOverdue = OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->whereNot('status', 'RESOLVED')
                ->exists();
            $hasOpenGatePass = $custody->gatePass()
                ->whereNot('status', 'VERIFIED')
                ->exists();
            $hasOpenObligation = $hasOpenIncident || $hasOpenLaundry || $hasOpenOverdue || $hasOpenGatePass;
            $status = match (true) {
                $allReturned && $hasOpenObligation => 'OBLIGATION_OPEN',
                $allReturned => 'CLOSED',
                $custody->status === 'OVERDUE' => 'OVERDUE',
                default => 'PARTIALLY_RETURNED',
            };
            $custody->update(['status' => $status, 'closed_at' => $status === 'CLOSED' ? now() : null]);
            if ($early) {
                $custody->earlyReturnRequests()->where('status', 'REQUESTED')->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            }
            $releasedTotal = (float) $custody->lines->sum('actual_released_quantity');
            $returnedTotal = (float) $custody->lines->sum('returned_quantity');
            DB::table('kpi_observations')->insert([
                'request_id' => $custody->request_id,
                'custody_id' => $custody->id,
                'recorded_by_user_id' => $spmu->id,
                'process_code' => 'CUSTODY_RETURN_COMPLIANCE',
                'started_at' => $custody->released_at,
                'completed_at' => now(),
                'duration_seconds' => $custody->released_at?->diffInSeconds(now()),
                'correct_count' => $custody->lines->where('returned_quantity', '>=', 0)->count(),
                'total_count' => $custody->lines->count(),
                'output_count' => $returnedTotal,
                'input_value' => $releasedTotal,
                'input_unit' => 'property units',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('RETURN_INSPECTED', $return, after: ['custody_status' => $status]);
            $this->notifications->send('RETURN_INSPECTED', collect([$custody->borrower]), "Return {$return->return_no} was physically counted and inspected. Status: {$status}.", $custody, ['SYSTEM', 'EMAIL']);

            return $return;
        }, 3);
    }

    public function requestEarlyReturn(CustodyTransaction $custody, User $borrower, array $quantities, string $proposedReturnAt, ?string $reason): EarlyReturnRequest
    {
        abort_unless($custody->borrower_user_id === $borrower->id && in_array($custody->status, ['ACTIVE', 'PARTIALLY_RETURNED', 'OVERDUE'], true), 403);

        return DB::transaction(function () use ($custody, $borrower, $quantities, $proposedReturnAt, $reason): EarlyReturnRequest {
            $early = EarlyReturnRequest::create([
                'early_return_no' => 'ER-'.now()->format('YmdHis').'-'.$custody->id,
                'custody_transaction_id' => $custody->id,
                'requested_by_user_id' => $borrower->id,
                'proposed_return_at' => $proposedReturnAt,
                'reason' => $reason,
                'status' => 'REQUESTED',
                'requested_at' => now(),
            ]);
            $selected = 0;
            foreach ($custody->lines()->get() as $line) {
                $outstanding = max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity);
                $quantity = (float) ($quantities[$line->id] ?? 0);
                if ($quantity < 0 || $quantity > $outstanding) {
                    throw ValidationException::withMessages(['early_return' => 'An Early Return quantity cannot exceed the outstanding issued quantity.']);
                }
                if ($quantity > 0) {
                    $early->lines()->create(['custody_line_id' => $line->id, 'proposed_quantity' => $quantity]);
                    $selected++;
                }
            }
            if ($selected === 0) {
                throw ValidationException::withMessages(['early_return' => 'Select at least one item quantity for Early Return.']);
            }
            $this->audit->record('EARLY_RETURN_REQUESTED', $early, reason: $reason, after: ['proposed_return_at' => $proposedReturnAt]);
            $this->notifications->send('EARLY_RETURN_REQUESTED', $this->spmuRecipients()->merge([$borrower])->unique('id'), "Early Return {$early->early_return_no} was requested for {$custody->custody_no}. No inventory changes occur until SPMU inspection.", $custody);

            return $early;
        });
    }

    private function spmuRecipients(): Collection
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('role_code', UserRole::Spmu->value)->whereNull('user_roles.revoked_at'))->get();
    }

    private function transactionHeader(string $type, object $source, User $actor, string $reason): int
    {
        return DB::table('inventory_transactions')->insertGetId([
            'actor_user_id' => $actor->id,
            'transaction_type' => $type,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'reason' => $reason,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transactionLine(int $transactionId, int $inventoryItemId, string $from, string $to, float $quantity, mixed $effectiveFrom = null, mixed $effectiveTo = null): void
    {
        DB::table('inventory_transaction_lines')->insert([
            'inventory_transaction_id' => $transactionId,
            'inventory_item_id' => $inventoryItemId,
            'from_state' => $from,
            'to_state' => $to,
            'quantity' => $quantity,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
