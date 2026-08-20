<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\ReturnTransaction;
use App\Models\Sanction;
use App\Models\SanctionRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyService
{
    public function __construct(private AuditService $audit) {}

    public function activePeriodFor(CarbonInterface|string|null $date = null): ?AcademicPeriod
    {
        $date = $date instanceof CarbonInterface
            ? CarbonImmutable::instance($date)
            : CarbonImmutable::parse($date ?: now());

        return AcademicPeriod::query()
            ->where('status', 'ACTIVE')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * Create one reviewable violation record per borrowing transaction.
     * A transaction may contain several findings, but it does not silently
     * increment the offense count more than once.
     */
    public function detectFromConfirmedReturn(
        CustodyTransaction $custody,
        ReturnTransaction $return,
        User $spmu
    ): ?BorrowerViolation {
        $return->loadMissing('lines');

        $reasons = [];
        $dueDate = $custody->due_at?->toDateString();
        $actualDate = $return->received_at?->toDateString();

        if ($dueDate && $actualDate && $actualDate > $dueDate) {
            $reasons[] = 'LATE_RETURN';
        }

        if ($return->lines->contains(fn ($line) => strtoupper((string) $line->condition_code) !== 'FINE')) {
            $reasons[] = 'SLDDP_ACCOUNTABILITY';
        }

        if ($reasons === []) {
            return null;
        }

        $period = $this->activePeriodFor($return->received_at);

        $violation = BorrowerViolation::query()->updateOrCreate(
            [
                'custody_transaction_id' => $custody->id,
                'violation_code' => 'BORROWING_VIOLATION',
            ],
            [
                'borrower_user_id' => $custody->borrower_user_id,
                'academic_period_id' => $period?->id,
                'details_json' => [
                    'reasons' => array_values(array_unique($reasons)),
                    'expected_return_date' => $dueDate,
                    'actual_return_date' => $actualDate,
                ],
                'status' => 'PENDING_REVIEW',
                'detected_at' => now(),
                'detected_by_user_id' => $spmu->id,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_remarks' => null,
            ]
        );

        $this->audit->record(
            'BORROWING_VIOLATION_DETECTED',
            $violation,
            after: $violation->details_json
        );

        return $violation;
    }

    /**
     * SPMU Head confirms or dismisses a detected violation. Sanction rules are
     * recommendations/configuration, not automatic punishment.
     */
    public function reviewViolation(
        BorrowerViolation $violation,
        User $spmuHead,
        string $decision,
        ?string $remarks = null
    ): ?Sanction {
        abort_unless(
            $spmuHead->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head may confirm sanctions.'
        );

        $decision = strtoupper($decision);
        if (! in_array($decision, ['CONFIRMED', 'DISMISSED'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose CONFIRMED or DISMISSED.',
            ]);
        }

        if ($violation->status !== 'PENDING_REVIEW') {
            throw ValidationException::withMessages([
                'decision' => 'This violation has already been reviewed.',
            ]);
        }

        return DB::transaction(function () use ($violation, $spmuHead, $decision, $remarks): ?Sanction {
            $locked = BorrowerViolation::query()->lockForUpdate()->findOrFail($violation->id);

            if ($decision === 'DISMISSED') {
                $locked->update([
                    'status' => 'DISMISSED',
                    'reviewed_by_user_id' => $spmuHead->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);

                $this->audit->record(
                    'BORROWING_VIOLATION_DISMISSED',
                    $locked,
                    reason: $remarks
                );

                return null;
            }

            $period = $locked->academicPeriod ?: $this->activePeriodFor($locked->detected_at);
            if (! $period) {
                throw ValidationException::withMessages([
                    'academic_period' => 'Configure and activate the applicable academic period before confirming this violation.',
                ]);
            }

            $offenseNo = BorrowerViolation::query()
                ->where('borrower_user_id', $locked->borrower_user_id)
                ->where('academic_period_id', $period->id)
                ->where('status', 'CONFIRMED')
                ->whereKeyNot($locked->id)
                ->count() + 1;

            $today = now()->toDateString();
            $rule = SanctionRule::query()
                ->where('offense_no', $offenseNo)
                ->where('status', 'ACTIVE')
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
                })
                ->where(function ($query) use ($today): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                })
                ->latest('id')
                ->first();

            $locked->update([
                'academic_period_id' => $period->id,
                'status' => 'CONFIRMED',
                'reviewed_by_user_id' => $spmuHead->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);

            if (! $rule) {
                $this->audit->record(
                    'BORROWING_VIOLATION_CONFIRMED_NO_SANCTION_RULE',
                    $locked,
                    reason: $remarks,
                    after: [
                        'offense_no' => $offenseNo,
                        'academic_period_id' => $period->id,
                    ]
                );

                return null;
            }

            $effectiveFrom = now();
            $effectiveTo = match ($rule->duration_mode) {
                'END_OF_PERIOD' => $period->end_date->copy()->endOfDay(),
                default => null,
            };

            $sanction = Sanction::query()->create([
                'borrower_violation_id' => $locked->id,
                'borrower_user_id' => $locked->borrower_user_id,
                'academic_period_id' => $period->id,
                'sanction_rule_id' => $rule->id,
                'offense_no' => $offenseNo,
                'sanction_code' => $rule->sanction_code,
                'sanction_label' => $rule->sanction_label,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'status' => 'ACTIVE',
                'confirmed_by_user_id' => $spmuHead->id,
                'confirmed_at' => now(),
                'remarks' => $remarks,
            ]);

            if (str_contains(strtoupper($rule->sanction_code), 'SUSPENSION')) {
                BorrowerRestriction::query()->create([
                    'borrower_user_id' => $locked->borrower_user_id,
                    'restriction_type' => 'SANCTION_SUSPENSION',
                    'reason' => $rule->sanction_label,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'status' => 'ACTIVE',
                    'imposed_by_user_id' => $spmuHead->id,
                    'sanction_id' => $sanction->id,
                ]);
            }

            $this->audit->record(
                'SANCTION_CONFIRMED',
                $sanction,
                reason: $remarks,
                after: [
                    'offense_no' => $offenseNo,
                    'academic_period' => $period->term_name,
                    'sanction_code' => $rule->sanction_code,
                    'effective_to' => $effectiveTo?->toIso8601String(),
                ]
            );

            return $sanction;
        }, 3);
    }
}
