<?php

namespace App\Console\Commands;

use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\NotificationEvent;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\RequestWorkflowService;
use Illuminate\Console\Command;

class ProcessOperationalDeadlines extends Command
{
    protected $signature = 'spmu:process-deadlines';

    protected $description = 'Expire undownloaded approvals and process due-soon/overdue custody records';

    public function handle(
        RequestWorkflowService $requests,
        NotificationService $notifications,
        AuditService $audit
    ): int {
        $expired = $requests->expireUndownloaded();

        $markedOverdue = 0;
        $overdue = 0;
        $dueSoon = 0;

        /*
        |--------------------------------------------------------------------------
        | SYSTEM SETTINGS
        |--------------------------------------------------------------------------
        |
        | overdue_grace_hours:
        |   Grace period BEFORE penalties/restrictions are applied.
        |
        | IMPORTANT:
        |   This does NOT control when the custody becomes OVERDUE.
        |   A custody becomes OVERDUE immediately after due_at passes.
        |
        */

        $graceHours = (int) SystemSetting::value(
            'overdue_grace_hours',
            24
        );

        $dueSoonHours = (int) SystemSetting::value(
            'due_soon_hours',
            24
        );

        $rate = SystemSetting::value(
            'daily_overdue_tariff'
        );

        /*
        |--------------------------------------------------------------------------
        | 1. DUE-SOON REMINDERS
        |--------------------------------------------------------------------------
        |
        | Notify borrowers whose custody return deadline is approaching.
        |
        */

        CustodyTransaction::query()
            ->with('borrower')
            ->whereIn('status', [
                'ACTIVE',
                'PARTIALLY_RETURNED',
                'EARLY_RETURN',
            ])
            ->whereBetween(
                'due_at',
                [
                    now(),
                    now()->addHours($dueSoonHours),
                ]
            )
            ->each(function (
                CustodyTransaction $custody
            ) use (
                &$dueSoon,
                $notifications
            ): void {
                $alreadySent = NotificationEvent::query()
                    ->where(
                        'event_code',
                        'RETURN_DUE_SOON'
                    )
                    ->where(
                        'source_type',
                        $custody->getMorphClass()
                    )
                    ->where(
                        'source_id',
                        $custody->id
                    )
                    ->exists();

                if ($alreadySent) {
                    return;
                }

                $notifications->send(
                    'RETURN_DUE_SOON',
                    collect([
                        $custody->borrower,
                    ]),
                    "Custody {$custody->custody_no} is due on {$custody->due_at->format('F j, Y g:i A')}.",
                    $custody
                );

                $dueSoon++;
            });

        /*
        |--------------------------------------------------------------------------
        | 2. MARK CUSTODY OVERDUE IMMEDIATELY
        |--------------------------------------------------------------------------
        |
        | Once the return deadline has passed, the custody is already overdue.
        |
        | Example:
        |
        | Return deadline:
        |   August 16, 2026 - 7:29 PM
        |
        | Current time:
        |   August 16, 2026 - 7:30 PM
        |
        | Status:
        |   OVERDUE
        |
        | The borrower can still be inside the configured grace period.
        | Penalties/restrictions are processed separately below.
        |
        */

        CustodyTransaction::query()
            ->whereIn('status', [
                'ACTIVE',
                'PARTIALLY_RETURNED',
                'EARLY_RETURN',
            ])
            ->whereNotNull('due_at')
            ->where(
                'due_at',
                '<',
                now()
            )
            ->each(function (
                CustodyTransaction $custody
            ) use (
                &$markedOverdue
            ): void {
                $custody->update([
                    'status' => 'OVERDUE',
                ]);

                $markedOverdue++;
            });

        /*
        |--------------------------------------------------------------------------
        | 3. PROCESS OVERDUE AFTER GRACE PERIOD
        |--------------------------------------------------------------------------
        |
        | The custody is already OVERDUE at this point.
        |
        | However:
        |
        | - Overdue case
        | - Borrower restriction
        | - Penalty/tariff
        | - Offense escalation
        |
        | only take effect once the configured grace period has expired.
        |
        */

        CustodyTransaction::query()
            ->with('borrower')
            ->where(
                'status',
                'OVERDUE'
            )
            ->whereNotNull('due_at')
            ->where(
                'due_at',
                '<=',
                now()->subHours($graceHours)
            )
            ->each(function (
                CustodyTransaction $custody
            ) use (
                &$overdue,
                $graceHours,
                $rate,
                $notifications,
                $audit
            ): void {
                /*
                |--------------------------------------------------------------------------
                | PRIOR OFFENSES
                |--------------------------------------------------------------------------
                */

                $priorOffenses = OverdueCase::query()
                    ->where(
                        'borrower_user_id',
                        $custody->borrower_user_id
                    )
                    ->whereNot(
                        'custody_transaction_id',
                        $custody->id
                    )
                    ->count();

                $offense = min(
                    3,
                    $priorOffenses + 1
                );

                /*
                |--------------------------------------------------------------------------
                | GRACE PERIOD EXPIRATION
                |--------------------------------------------------------------------------
                */

                $graceExpires = $custody
                    ->due_at
                    ->copy()
                    ->addHours($graceHours);

                /*
                |--------------------------------------------------------------------------
                | NUMBER OF PENALTY DAYS
                |--------------------------------------------------------------------------
                |
                | Penalty starts AFTER the grace period.
                |
                */

                $days = max(
                    1,
                    (int) ceil(
                        $graceExpires->diffInMinutes(now()) / 1440
                    )
                );

                /*
                |--------------------------------------------------------------------------
                | CREATE / UPDATE OVERDUE CASE
                |--------------------------------------------------------------------------
                */

                $case = OverdueCase::query()
                    ->firstOrNew([
                        'custody_transaction_id' => $custody->id,
                    ]);

                $isNew = ! $case->exists;

                $case->fill([
                    'borrower_user_id' =>
                        $custody->borrower_user_id,

                    'grace_expires_at' =>
                        $graceExpires,

                    /*
                    | Actual overdue started exactly at
                    | the return deadline.
                    */
                    'overdue_started_at' =>
                        $case->overdue_started_at
                            ?: $custody->due_at,

                    'offense_level' =>
                        $case->offense_level
                            ?: $offense,

                    'rate_snapshot' =>
                        is_numeric($rate)
                            ? $rate
                            : null,

                    'accrued_amount' =>
                        is_numeric($rate)
                            ? round(
                                $days * (float) $rate,
                                2
                            )
                            : 0,

                    'sanction_type' =>
                        $case->sanction_type
                            ?: match ($offense) {
                                1 => 'NOTICE',
                                2 => 'REPRIMAND',
                                default => 'SEMESTER_SUSPENSION',
                            },

                    'status' => 'OVERDUE',
                ])->save();

                /*
                |--------------------------------------------------------------------------
                | OVERDUE RETURN RESTRICTION
                |--------------------------------------------------------------------------
                */

                BorrowerRestriction::query()
                    ->firstOrCreate(
                        [
                            'borrower_user_id' =>
                                $custody->borrower_user_id,

                            'restriction_type' =>
                                'OVERDUE_RETURN',

                            'status' =>
                                'ACTIVE',
                        ],
                        [
                            'reason' =>
                                "Unresolved overdue custody {$custody->custody_no}.",

                            'effective_from' =>
                                now(),
                        ]
                    );

                /*
                |--------------------------------------------------------------------------
                | THIRD OFFENSE RESTRICTION
                |--------------------------------------------------------------------------
                */

                if ($offense >= 3) {
                    BorrowerRestriction::query()
                        ->firstOrCreate(
                            [
                                'borrower_user_id' =>
                                    $custody->borrower_user_id,

                                'restriction_type' =>
                                    'THIRD_OFFENSE_SUSPENSION',

                                'status' =>
                                    'ACTIVE',
                            ],
                            [
                                'reason' =>
                                    'Third overdue offense; semester end remains configurable.',

                                'effective_from' =>
                                    now(),
                            ]
                        );
                }

                /*
                |--------------------------------------------------------------------------
                | KEEP CUSTODY STATUS OVERDUE
                |--------------------------------------------------------------------------
                */

                if ($custody->status !== 'OVERDUE') {
                    $custody->update([
                        'status' => 'OVERDUE',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | FIRST-TIME OVERDUE ESCALATION
                |--------------------------------------------------------------------------
                |
                | Send notification and create audit trail once the
                | grace period has expired and an overdue case is created.
                |
                */

                if ($isNew) {
                    $notifications->send(
                        'BORROWING_OVERDUE',
                        collect([
                            $custody->borrower,
                        ]),
                        "Custody {$custody->custody_no} remains overdue after the {$graceHours}-hour grace period. Offense level: {$offense}.",
                        $custody
                    );

                    $audit->record(
                        'CUSTODY_MARKED_OVERDUE',
                        $case,
                        after: [
                            'offense_level' =>
                                $offense,

                            'rate_snapshot' =>
                                $rate,

                            'due_at' =>
                                $custody->due_at
                                    ->toIso8601String(),

                            'grace_expires_at' =>
                                $graceExpires
                                    ->toIso8601String(),
                        ]
                    );
                }

                $overdue++;
            });

        /*
        |--------------------------------------------------------------------------
        | COMMAND OUTPUT
        |--------------------------------------------------------------------------
        */

        $this->info(
            "Processed {$expired} expired approval(s), "
            ."{$dueSoon} due-soon reminder(s), "
            ."{$markedOverdue} newly overdue custody record(s), "
            ."and {$overdue} overdue record(s) beyond the grace period."
        );

        return self::SUCCESS;
    }
}