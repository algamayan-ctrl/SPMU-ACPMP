<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\EarlyReturnRequest;
use App\Models\GeneratedDocument;
use App\Models\GatePass;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustodyService
{
    public function __construct(
        private DocumentService $documents,
        private AuditService $audit,
        private NotificationService $notifications,
    ) {}

    /**
     * Create the current pickup/custody record immediately after SPMU
     * approval and inventory reservation.
     *
     * This method intentionally does not assign a pickup time. The SPMU
     * Action Officer schedules the pickup window later from the custody
     * workspace. It is idempotent so replaying the approval-side call does
     * not duplicate the custody transaction or its lines.
     */
    public function ensurePickupRecord(
        BorrowingRequest $request,
        User $actor
    ): CustodyTransaction {
        return DB::transaction(function () use ($request): CustodyTransaction {
            $request = BorrowingRequest::query()
                ->with([
                    'currentVersion.items.allocation',
                ])
                ->lockForUpdate()
                ->findOrFail($request->id);

            $version = $request->currentVersion;

            if (! $version) {
                throw ValidationException::withMessages([
                    'custody' => 'The approved request version could not be found.',
                ]);
            }

            $dueAt = $version->return_due_at;

            if (! $dueAt && $version->return_date) {
                $timezone = config('app.timezone') ?: 'Asia/Manila';
                $dueAt = CarbonImmutable::parse(
                    $version->return_date,
                    $timezone
                )->endOfDay();
            }

            if (! $dueAt) {
                throw ValidationException::withMessages([
                    'custody' => 'The approved Expected Return Date could not be found.',
                ]);
            }

            $custody = CustodyTransaction::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                ],
                [
                    'custody_no' => 'CUS-'
                        .now()->format('Ymd')
                        .'-'
                        .str_pad(
                            (string) $request->id,
                            5,
                            '0',
                            STR_PAD_LEFT
                        ),
                    'request_version_id' => $version->id,
                    'borrower_user_id' => $request->borrower_user_id,
                    'status' => 'PREPARING_RELEASE',

                    // Pickup is scheduled later by the SPMU Action Officer.
                    'scheduled_release_at' => null,
                    'pickup_expires_at' => null,
                    'pickup_expired_at' => null,
                    'pickup_scheduled_by_user_id' => null,
                    'pickup_scheduled_at' => null,

                    'due_at' => $dueAt,
                    'prepared_by_user_id' => null,
                    'prepared_at' => null,
                    'released_by_user_id' => null,
                    'released_at' => null,
                    'acknowledged_at' => null,
                    'closed_at' => null,
                ]
            );

            foreach ($version->items as $item) {
                $allocation = $item->allocation;

                if (! $allocation || $allocation->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'custody' => 'The approved inventory reservation is incomplete. Re-run SPMU verification before preparing release.',
                    ]);
                }

                $approvedQuantity = (float) (
                    $item->approved_quantity
                    ?? $allocation->allocated_quantity
                );

                if ($approvedQuantity <= 0) {
                    throw ValidationException::withMessages([
                        'custody' => 'An approved custody quantity must be greater than zero.',
                    ]);
                }

                $custody->lines()->firstOrCreate(
                    [
                        'request_item_id' => $item->id,
                    ],
                    [
                        'allocation_id' => $allocation->id,
                        'approved_quantity' => $approvedQuantity,
                        'quantity_to_receive' => $approvedQuantity,
                        'actual_released_quantity' => 0,
                        'returned_quantity' => 0,
                    ]
                );
            }

            return $custody->fresh([
                'lines.requestItem.inventoryItem',
                'borrower',
                'request.currentVersion',
            ]);
        }, 3);
    }

    /**
     * Expire pickup claim windows that were not completed before the
     * configured cutoff.
     *
     * Expiring the pickup window does not cancel the approved request or
     * release its inventory reservation. It only closes the current claim
     * window so the SPMU Action Officer can schedule a new pickup window.
     * Any earlier preparation must be reconfirmed for the new schedule.
     */
    public function expirePickupWindows(): int
    {
        $expired = 0;

        CustodyTransaction::query()
            ->where('status', 'PREPARING_RELEASE')
            ->whereNull('released_at')
            ->whereNotNull('pickup_expires_at')
            ->whereNull('pickup_expired_at')
            ->where('pickup_expires_at', '<', now())
            ->orderBy('id')
            ->each(function (CustodyTransaction $custody) use (&$expired): void {
                DB::transaction(function () use ($custody, &$expired): void {
                    $locked = CustodyTransaction::query()
                        ->lockForUpdate()
                        ->find($custody->id);

                    if (
                        ! $locked
                        || $locked->status !== 'PREPARING_RELEASE'
                        || $locked->released_at
                        || ! $locked->pickup_expires_at
                        || $locked->pickup_expired_at
                        || $locked->pickup_expires_at->gte(now())
                    ) {
                        return;
                    }

                    $expiredAt = now();

                    $locked->update([
                        'pickup_expired_at' => $expiredAt,
                        'prepared_by_user_id' => null,
                        'prepared_at' => null,
                    ]);

                    $locked->lines()->update([
                        'item_status' => 'CONFIRMED',
                    ]);

                    $this->audit->record(
                        'PICKUP_WINDOW_EXPIRED',
                        $locked,
                        after: [
                            'pickup_expires_at' => $locked->pickup_expires_at->toIso8601String(),
                            'pickup_expired_at' => $expiredAt->toIso8601String(),
                            'reservation_released' => false,
                            'requires_rescheduling' => true,
                        ]
                    );

                    $locked->loadMissing('borrower');

                    if ($locked->borrower) {
                        $this->notifications->send(
                            'PICKUP_WINDOW_EXPIRED',
                            collect([$locked->borrower]),
                            "The pickup window for {$locked->custody_no} has expired. The approved reservation remains in place; wait for SPMU to schedule a new pickup window.",
                            $locked
                        );
                    }

                    $expired++;
                }, 3);
            });

        return $expired;
    }

    public function schedulePickup(
        CustodyTransaction $custody,
        User $spmu,
        string $pickupAt,
        string $pickupExpiresAt
    ): void {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        $timezone = config('app.timezone') ?: 'Asia/Manila';
        $pickup = CarbonImmutable::parse($pickupAt, $timezone);
        $expires = CarbonImmutable::parse($pickupExpiresAt, $timezone);

        $custody->loadMissing('request.currentVersion', 'borrower');
        $version = $custody->request?->currentVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'pickup_at' => 'The approved request schedule could not be found.',
            ]);
        }

        $approvedSchedule = $version->getAttribute('schedule_date')
            ?: $version->getAttribute('needed_from');

        if (! $approvedSchedule) {
            throw ValidationException::withMessages([
                'pickup_at' => 'The approved Schedule Date could not be found.',
            ]);
        }

        $approvedDate = CarbonImmutable::parse($approvedSchedule, $timezone)->toDateString();

        if ($pickup->toDateString() !== $approvedDate) {
            throw ValidationException::withMessages([
                'pickup_at' => 'Pickup must be scheduled on the approved Schedule Date: '
                    .CarbonImmutable::parse($approvedDate, $timezone)->format('F j, Y').'.',
            ]);
        }

        if ($expires->toDateString() !== $pickup->toDateString()) {
            throw ValidationException::withMessages([
                'pickup_expires_at' => 'Pickup time and pickup expiration must be on the same calendar date.',
            ]);
        }

        if ($expires->lte($pickup)) {
            throw ValidationException::withMessages([
                'pickup_expires_at' => 'Pickup expiration must be later than the pickup time.',
            ]);
        }

        DB::transaction(function () use ($custody, $spmu, $pickup, $expires): void {
            $locked = CustodyTransaction::query()
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($locked->status !== 'PREPARING_RELEASE' || $locked->released_at) {
                throw ValidationException::withMessages([
                    'pickup_at' => 'This pickup transaction has already moved to another state.',
                ]);
            }

            $before = [
                'scheduled_release_at' => $locked->scheduled_release_at,
                'pickup_expires_at' => $locked->pickup_expires_at,
                'pickup_scheduled_by_user_id' => $locked->pickup_scheduled_by_user_id,
                'prepared_at' => $locked->prepared_at,
            ];

            $locked->update([
                'scheduled_release_at' => $pickup,
                'pickup_expires_at' => $expires,
                'pickup_scheduled_by_user_id' => $spmu->id,
                'pickup_scheduled_at' => now(),
                'pickup_expired_at' => null,
                // A changed pickup schedule requires physical preparation to be reconfirmed.
                'prepared_by_user_id' => null,
                'prepared_at' => null,
            ]);

            $this->audit->record(
                'PICKUP_SCHEDULED',
                $locked,
                before: $before,
                after: [
                    'pickup_at' => $pickup->toIso8601String(),
                    'pickup_expires_at' => $expires->toIso8601String(),
                    'scheduled_by_user_id' => $spmu->id,
                ]
            );

            $locked->loadMissing('borrower');

            if ($locked->borrower) {
                $this->notifications->send(
                    'PICKUP_SCHEDULED',
                    collect([$locked->borrower]),
                    "Pickup for {$locked->custody_no} is scheduled on {$pickup->format('F j, Y g:i A')} and may be claimed until {$expires->format('g:i A')}.",
                    $locked
                );
            }
        }, 3);
    }

    public function updateReceiptQuantities(CustodyTransaction $custody, User $spmu, array $quantities, array $reasons): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $quantities): void {
            foreach ($custody->lines()->get() as $line) {
                $approvedRaw = (float) $line->approved_quantity;

                if (abs($approvedRaw - round($approvedRaw)) > 0.000001) {
                    throw ValidationException::withMessages([
                        'quantities' => 'Approved quantity must be a whole number before physical preparation.',
                    ]);
                }

                $approved = (int) round($approvedRaw);
                $submittedQuantity = $quantities[$line->id] ?? $approved;

                if (filter_var($submittedQuantity, FILTER_VALIDATE_INT) === false) {
                    throw ValidationException::withMessages([
                        'quantities' => 'Prepared quantity must be a whole number.',
                    ]);
                }

                $quantity = (int) $submittedQuantity;

                if ($quantity !== $approved) {
                    throw ValidationException::withMessages([
                        'quantities' => 'Prepared quantity must exactly match the verified approved quantity. Revise the request if the quantity must change.',
                    ]);
                }

                $line->update([
                    'quantity_to_receive' => $approved,
                    'adjustment_reason' => null,
                ]);
            }

            $custody->update([
                'prepared_at' => null,
                'prepared_by_user_id' => null,
            ]);

            $this->audit->record(
                'FINAL_ISSUED_QUANTITY_RECORDED',
                $custody,
                after: ['quantities' => $quantities]
            );
        });
    }

    public function prepare(CustodyTransaction $custody, User $spmu): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE',
            403
        );

        $custody->loadMissing([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        if (! $custody->hasPickupSchedule()) {
            throw ValidationException::withMessages([
                'prepare' => 'Schedule the pickup window before confirming physical preparation.',
            ]);
        }

        foreach ($custody->lines as $line) {
            if (
                abs(
                    (float) $line->quantity_to_receive
                    - (float) $line->approved_quantity
                ) > 0.000001
            ) {
                throw ValidationException::withMessages([
                    'prepare' => 'Every prepared quantity must exactly match the verified approved quantity.',
                ]);
            }
        }

        DB::transaction(function () use ($custody, $spmu): void {
            $custody->update([
                'prepared_by_user_id' => $spmu->id,
                'prepared_at' => now(),
            ]);

            $custody->lines()->update([
                'item_status' => 'PREPARED',
                'adjustment_reason' => null,
            ]);
        });

        $fresh = $custody->fresh([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        $this->documents->borrowerSlip($fresh);

        $offCampusLine = $fresh->lines->first(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        if ($offCampusLine) {
            $gatePassDocument = $this->documents->conditionalForm(
                $fresh,
                'GATE_PASS'
            );

            if ($fresh->gatePass) {
                $fresh->gatePass->update([
                    'custody_line_id' => $offCampusLine->id,
                    'pass_document_id' => $gatePassDocument->id,
                    'bearer_name' => $fresh->borrower?->full_name,
                    'destination' => $fresh->request?->currentVersion?->location,
                    'purpose' => $fresh->request?->currentVersion?->purpose_event,
                    'status' => $fresh->gatePass->status === 'VERIFIED'
                        ? 'VERIFIED'
                        : 'PENDING',
                ]);
            } else {
                GatePass::query()->create([
                    'custody_transaction_id' => $fresh->id,
                    'custody_line_id' => $offCampusLine->id,
                    'pass_document_id' => $gatePassDocument->id,
                    'bearer_name' => $fresh->borrower?->full_name,
                    'destination' => $fresh->request?->currentVersion?->location,
                    'purpose' => $fresh->request?->currentVersion?->purpose_event,
                    'status' => 'PENDING',
                ]);
            }
        }

        $hasLaundry = $fresh->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        if ($hasLaundry) {
            $this->documents->conditionalForm(
                $fresh->fresh(),
                'LAUNDRY_FORM'
            );
        }

        $this->audit->record(
            'RELEASE_PREPARED',
            $fresh,
            reason: 'SPMU confirmed exact approved quantities and generated the required physical forms.'
        );
    }

    public function acknowledge(CustodyTransaction $custody, User $borrower): void
    {
        abort_unless($custody->borrower_user_id === $borrower->id && $custody->status === 'PREPARING_RELEASE', 403);
        if (! $custody->prepared_at) {
            throw ValidationException::withMessages(['acknowledge' => 'SPMU must verify the prepared quantities before borrower acknowledgement.']);
        }
        DB::transaction(function () use ($custody): void {
            $hasLinen = $custody->lines()
                ->whereHas('requestItem.inventoryItem', fn ($query) => $query->where('laundry_required', true))
                ->exists();

            /*
             * This is a system acknowledgement only. It is NOT an electronic
             * signature. All documents that require signatures are printed,
             * signed by hand, scanned, and uploaded/verified as evidence.
             * Legacy signature columns are explicitly cleared and are not used
             * by the active workflow.
             */
            $custody->update([
                'borrower_ack_signature_snapshot_id' => null,
                'laundry_borrower_signature_snapshot_id' => null,
                'laundry_approver_signature_snapshot_id' => null,
                'acknowledged_at' => now(),
            ]);

            $custody->lines()->update(['item_status' => 'PREPARED']);

            GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $custody->id)
                ->where('document_type', 'BORROWER_SLIP')
                ->where('status', 'FINAL')
                ->update([
                    'status' => 'SUPERSEDED',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Replaced by acknowledged slip version.',
                ]);

            $this->documents->borrowerSlip($custody->fresh());

            if ($hasLinen) {
                $this->documents->replaceConditionalForm($custody->fresh(), 'LAUNDRY_FORM');
            } else {
                $this->documents->refreshPacketIfReady($custody->fresh());
            }

            $this->audit->record(
                'BORROWER_RECEIPT_ACKNOWLEDGED',
                $custody,
                after: [
                    'acknowledged_at' => $custody->fresh()->acknowledged_at?->toIso8601String(),
                    'signature_method' => 'NONE_SYSTEM_ACKNOWLEDGEMENT_ONLY',
                ]
            );
        });
    }

    public function release(CustodyTransaction $custody, User $spmu, ?string $remarks = null): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE',
            403
        );

        if (! $custody->prepared_at) {
            throw ValidationException::withMessages([
                'release' => 'SPMU physical preparation is required before release.',
            ]);
        }

        if (! $custody->acknowledged_at) {
            throw ValidationException::withMessages([
                'release' => "The borrower must review and confirm the prepared Borrower's Slip before physical release.",
            ]);
        }

        $custody->loadMissing('request.currentVersion', 'gatePass', 'lines.requestItem.inventoryItem');
        $hasOffCampusItem = $custody->lines->contains(
            fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS'
        );

        if ($hasOffCampusItem && ! $custody->gatePass) {
            throw ValidationException::withMessages([
                'release' => 'Generate and print the Gate Pass before release. Required signatures are handwritten/wet signatures on the printed form; the signed scan is uploaded and verified as evidence.',
            ]);
        }
        $hasLinen = $custody->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem->inventoryItem->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        $laundryDocument = null;

        if ($hasLinen) {
            /*
             * The Laundry Form is a physical working document. It is generated
             * by SPMU and travels with the borrower to Laundry; it is not
             * digitally approved by the Laundry Worker or borrower.
             */
            $laundryDocument = GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $custody->id)
                ->where('document_type', 'LAUNDRY_FORM')
                ->where('status', 'FINAL')
                ->latest('id')
                ->first();

            if (! $laundryDocument) {
                $laundryDocument = $this->documents->conditionalForm(
                    $custody->fresh(),
                    'LAUNDRY_FORM'
                );
            }
        }

        DB::transaction(function () use ($custody, $spmu, $hasLinen, $laundryDocument, $remarks): void {
            $custody->loadMissing('lines.requestItem.inventoryItem', 'lines.allocation', 'borrower', 'request');

            $releaseReason = trim((string) $remarks);
            if ($releaseReason === '') {
                $releaseReason = 'Physical count, condition, and required handwritten signatures confirmed.';
            }

            $transactionId = $this->transactionHeader(
                'PHYSICAL_RELEASE',
                $custody,
                $spmu,
                $releaseReason
            );
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

            if ($hasLinen) {
                $job = LaundryJob::query()->updateOrCreate(
                    [
                        'custody_transaction_id' => $custody->id,
                    ],
                    [
                        'generated_document_id' => $laundryDocument?->id,
                        'status' => 'FOR_LAUNDRY',
                        'ready_at' => null,
                        'released_to_borrower_at' => null,
                        'form_verified_by_user_id' => null,
                        'form_verified_at' => null,
                        'completed_at' => null,
                    ]
                );

                foreach ($custody->lines as $line) {
                    if (
                        ! $line->requestItem->inventoryItem->laundry_required
                        || (float) $line->actual_released_quantity <= 0
                    ) {
                        continue;
                    }

                    LaundryJobLine::query()->updateOrCreate(
                        [
                            'custody_line_id' => $line->id,
                        ],
                        [
                            'laundry_job_id' => $job->id,
                            'issued_quantity' => $line->actual_released_quantity,
                            'received_quantity' => null,
                            'issue_type' => null,
                            'affected_quantity' => 0,
                            'completed_quantity' => null,
                            'remarks' => null,
                        ]
                    );

                    $line->update([
                        'compliance_status' => 'FOR_LAUNDRY',
                    ]);
                }
            }

            $custody->update([
                'released_by_user_id' => $spmu->id,
                'released_at' => now(),
                'status' => 'ACTIVE',
            ]);

            /*
             * Off-campus Gate Passes are physical wet-signed forms.
             * After physical issuance, the current form is ready to be
             * accomplished by the guard and returned as a scan. No digital
             * SPMU signature/approval step is required.
             */
            $custody->gatePass()
                ->where('status', '!=', 'VERIFIED')
                ->update([
                    'status' => 'READY_FOR_PRINTING',
                    'prepared_verified_by_user_id' => null,
                    'prepared_verifier_signature_snapshot_id' => null,
                    'prepared_verified_at' => null,
                    'approved_by_user_id' => null,
                    'approver_signature_snapshot_id' => null,
                    'temporary_delegation_id' => null,
                    'approved_at' => null,
                ]);

            $this->audit->record('ITEMS_RELEASED', $custody, after: ['released_by' => $spmu->id, 'released_at' => now()->toIso8601String()]);
            $this->notifications->send('ITEMS_RELEASED', collect([$custody->borrower]), "Items under {$custody->custody_no} were physically released. Return deadline: {$custody->due_at->format('F j, Y g:i A')}.", $custody);

            if ($hasLinen) {
                $laundryWorkers = User::query()
                    ->where(
                        'access_classification',
                        AccessClassification::LaundryWorker->value
                    )
                    ->where('account_status', 'ACTIVE')
                    ->get();

                if ($laundryWorkers->isNotEmpty()) {
                    $this->notifications->send(
                        'LINEN_FOR_LAUNDRY',
                        $laundryWorkers,
                        "A linen transaction under {$custody->custody_no} is for Laundry. The borrower will bring the used linen and physical Laundry Form after use.",
                        $custody,
                        ['SYSTEM']
                    );
                }
            }
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

            $laundryJob = LaundryJob::query()
                ->where('custody_transaction_id', $custody->id)
                ->first();

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

                if ($item->laundry_required && $laundryJob) {
                    /*
                     * New linen flow:
                     * Borrower -> Laundry -> Borrower -> SPMU.
                     *
                     * Laundry completion does not return inventory to Available.
                     * Only SPMU's final physical return inspection may move the
                     * cleaned linen from Borrowed to its final inventory state.
                     */
                    if (
                        $laundryJob->status !== 'FOR_SPMU_FINAL_CHECK'
                        || ! $laundryJob->form_verified_at
                    ) {
                        throw ValidationException::withMessages([
                            'return' =>
                                'This linen cannot be finalized yet. Laundry must upload the accomplished form, release the cleaned linen to the borrower, and SPMU must verify/encode the form first.',
                        ]);
                    }

                    $disposition = $condition === 'FINE'
                        ? 'AVAILABLE'
                        : match ($condition) {
                            'MISSING', 'LOST' => 'LOST',
                            'STOLEN' => 'STOLEN',
                            'DESTROYED' => 'DESTROYED',
                            default => 'DAMAGED_MAINTENANCE',
                        };
                } else {
                    /*
                     * Historical compatibility for older laundry records that
                     * were created only after an SPMU return.
                     */
                    $disposition = $condition === 'FINE'
                        ? ($item->laundry_required ? 'LAUNDRY' : 'AVAILABLE')
                        : match ($condition) {
                            'MISSING', 'LOST' => 'LOST',
                            'STOLEN' => 'STOLEN',
                            'DESTROYED' => 'DESTROYED',
                            default => 'DAMAGED_MAINTENANCE',
                        };
                }
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

            $custody->refresh()->load('lines.requestItem.inventoryItem');

            if ($laundryJob) {
                $allLaundryReturned = $custody->lines
                    ->filter(
                        fn ($line) =>
                            (bool) $line->requestItem->inventoryItem->laundry_required
                            && (float) $line->actual_released_quantity > 0
                    )
                    ->every(
                        fn ($line) =>
                            (float) $line->returned_quantity
                            >= (float) $line->actual_released_quantity
                    );

                if ($allLaundryReturned) {
                    $laundryJob->update([
                        'status' => 'LAUNDRY_COMPLETED',
                        'completed_at' => now(),
                    ]);

                    $custody->lines()
                        ->whereHas(
                            'requestItem.inventoryItem',
                            fn ($query) =>
                                $query->where('laundry_required', true)
                        )
                        ->update([
                            'compliance_status' => 'LAUNDRY_COMPLETED',
                        ]);
                }
            }

            $allReturned = $custody->lines->every(
                fn ($line) =>
                    (float) $line->returned_quantity
                    >= (float) $line->actual_released_quantity
            );

            $overdue = OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->first();

            if ($allReturned) {
                /*
                 * PENDING_RETURN means that physical property is still
                 * outstanding. Once every released quantity for this custody
                 * has been physically returned, lift that restriction unless
                 * the same borrower still has another custody with an
                 * outstanding issued quantity.
                 */
                $hasOtherOutstandingCustody = CustodyTransaction::query()
                    ->where('borrower_user_id', $custody->borrower_user_id)
                    ->whereKeyNot($custody->id)
                    ->whereHas(
                        'lines',
                        fn ($query) =>
                            $query->whereColumn(
                                'returned_quantity',
                                '<',
                                'actual_released_quantity'
                            )
                    )
                    ->exists();

                if (! $hasOtherOutstandingCustody) {
                    BorrowerRestriction::query()
                        ->where('borrower_user_id', $custody->borrower_user_id)
                        ->where('restriction_type', 'PENDING_RETURN')
                        ->where('status', 'ACTIVE')
                        ->update([
                            'status' => 'LIFTED',
                            'effective_to' => now(),
                            'lifted_by_user_id' => $spmu->id,
                        ]);
                }
            }

            if ($overdue && $allReturned && $overdue->status !== 'RESOLVED') {
                $rate = SystemSetting::value('daily_overdue_tariff');
                $days = max(
                    1,
                    (int) ceil(
                        $overdue->grace_expires_at->diffInMinutes(now())
                        / 1440
                    )
                );

                $overdue->update([
                    'rate_snapshot' => is_numeric($rate) ? $rate : null,
                    'accrued_amount' => is_numeric($rate)
                        ? round($days * (float) $rate, 2)
                        : 0,
                    'status' => 'RETURNED_PENDING_SETTLEMENT',
                ]);

                /*
                 * The physical-return restriction is finished at this point,
                 * but the late-return accountability remains open until the
                 * assessed fee is settled or formally waived.
                 */
                BorrowerRestriction::query()->firstOrCreate(
                    [
                        'borrower_user_id' => $custody->borrower_user_id,
                        'restriction_type' => 'OVERDUE_RETURN',
                        'status' => 'ACTIVE',
                    ],
                    [
                        'reason' =>
                            'Late return under '
                            .$custody->custody_no
                            .' is awaiting accountability settlement.',
                        'effective_from' => now(),
                        'imposed_by_user_id' => $spmu->id,
                    ]
                );
            }
            $hasOpenIncident = Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
                ->exists();
            $hasOpenLaundry = LaundryRecord::query()
                ->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custody->id))
                ->where('status', '!=', 'VERIFIED')
                ->exists();
            $hasOpenOverdue = OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', '!=', 'RESOLVED')
                ->exists();
            $hasOpenGatePass = $custody->gatePass()
                ->where('status', '!=', 'VERIFIED')
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
        $custody = $custody->fresh() ?? $custody;

        abort_unless(
            $custody->borrower_user_id === $borrower->id
                && $custody->released_at
                && in_array(
                    $custody->status,
                    ['ACTIVE', 'PARTIALLY_RETURNED', 'OVERDUE'],
                    true
                ),
            403
        );

        $early = DB::transaction(function () use ($custody, $borrower, $quantities, $proposedReturnAt, $reason): EarlyReturnRequest {
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
                $outstanding = max(
                    0,
                    (float) $line->actual_released_quantity
                        - (float) $line->returned_quantity
                );

                $quantity = (float) ($quantities[$line->id] ?? 0);

                if ($quantity < 0 || $quantity > $outstanding) {
                    throw ValidationException::withMessages([
                        'early_return' => 'An Early Return quantity cannot exceed the outstanding issued quantity.',
                    ]);
                }

                if ($quantity > 0) {
                    $early->lines()->create([
                        'custody_line_id' => $line->id,
                        'proposed_quantity' => $quantity,
                    ]);
                    $selected++;
                }
            }

            if ($selected === 0) {
                throw ValidationException::withMessages([
                    'early_return' => 'Select at least one item quantity for Early Return.',
                ]);
            }

            return $early;
        });

        try {
            $this->audit->record(
                'EARLY_RETURN_REQUESTED',
                $early,
                reason: $reason,
                after: ['proposed_return_at' => $proposedReturnAt]
            );
        } catch (Throwable $exception) {
            Log::warning('Early Return audit recording failed after persistence.', [
                'early_return_id' => $early->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $this->notifications->send(
                'EARLY_RETURN_REQUESTED',
                $this->spmuRecipients()
                    ->merge([$borrower])
                    ->unique('id'),
                "Early Return {$early->early_return_no} was requested for {$custody->custody_no}. No inventory changes occur until SPMU inspection.",
                $custody
            );
        } catch (Throwable $exception) {
            Log::warning('Early Return notification failed after persistence.', [
                'early_return_id' => $early->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $early;
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
