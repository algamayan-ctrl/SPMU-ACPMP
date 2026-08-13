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

    public function handle(RequestWorkflowService $requests, NotificationService $notifications, AuditService $audit): int
    {
        $expired = $requests->expireUndownloaded();
        $overdue = 0;
        $dueSoon = 0;
        $graceHours = (int) SystemSetting::value('overdue_grace_hours', 24);
        $dueSoonHours = (int) SystemSetting::value('due_soon_hours', 24);
        $rate = SystemSetting::value('daily_overdue_tariff');

        CustodyTransaction::query()
            ->with('borrower')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_RETURNED', 'EARLY_RETURN', 'OVERDUE'])
            ->whereBetween('due_at', [now(), now()->addHours($dueSoonHours)])
            ->each(function (CustodyTransaction $custody) use (&$dueSoon, $notifications): void {
                if (NotificationEvent::query()->where('event_code', 'RETURN_DUE_SOON')->where('source_type', $custody->getMorphClass())->where('source_id', $custody->id)->exists()) {
                    return;
                }
                $notifications->send('RETURN_DUE_SOON', collect([$custody->borrower]), "Custody {$custody->custody_no} is due on {$custody->due_at->format('F j, Y g:i A')}.", $custody);
                $dueSoon++;
            });

        CustodyTransaction::query()
            ->with('borrower')
            ->whereIn('status', ['ACTIVE', 'PARTIALLY_RETURNED', 'EARLY_RETURN'])
            ->where('due_at', '<', now()->subHours($graceHours))
            ->each(function (CustodyTransaction $custody) use (&$overdue, $graceHours, $rate, $notifications, $audit): void {
                $priorOffenses = OverdueCase::query()->where('borrower_user_id', $custody->borrower_user_id)->whereNot('custody_transaction_id', $custody->id)->count();
                $offense = min(3, $priorOffenses + 1);
                $graceExpires = $custody->due_at->copy()->addHours($graceHours);
                $days = max(1, (int) ceil($graceExpires->diffInMinutes(now()) / 1440));
                $case = OverdueCase::query()->firstOrNew(['custody_transaction_id' => $custody->id]);
                $isNew = ! $case->exists;
                $case->fill([
                    'borrower_user_id' => $custody->borrower_user_id,
                    'grace_expires_at' => $graceExpires,
                    'overdue_started_at' => $case->overdue_started_at ?: now(),
                    'offense_level' => $case->offense_level ?: $offense,
                    'rate_snapshot' => is_numeric($rate) ? $rate : null,
                    'accrued_amount' => is_numeric($rate) ? round($days * (float) $rate, 2) : 0,
                    'sanction_type' => $case->sanction_type ?: match ($offense) {
                        1 => 'NOTICE', 2 => 'REPRIMAND', default => 'SEMESTER_SUSPENSION'
                    },
                    'status' => 'OVERDUE',
                ])->save();
                BorrowerRestriction::query()->firstOrCreate([
                    'borrower_user_id' => $custody->borrower_user_id,
                    'restriction_type' => 'OVERDUE_RETURN',
                    'status' => 'ACTIVE',
                ], [
                    'reason' => "Unresolved overdue custody {$custody->custody_no}.",
                    'effective_from' => now(),
                ]);
                if ($offense >= 3) {
                    BorrowerRestriction::query()->firstOrCreate([
                        'borrower_user_id' => $custody->borrower_user_id,
                        'restriction_type' => 'THIRD_OFFENSE_SUSPENSION',
                        'status' => 'ACTIVE',
                    ], [
                        'reason' => 'Third overdue offense; semester end remains configurable.',
                        'effective_from' => now(),
                    ]);
                }
                $custody->update(['status' => 'OVERDUE']);
                if ($isNew) {
                    $notifications->send('BORROWING_OVERDUE', collect([$custody->borrower]), "Custody {$custody->custody_no} is overdue after the {$graceHours}-hour grace period. Offense level: {$offense}.", $custody);
                    $audit->record('CUSTODY_MARKED_OVERDUE', $case, after: ['offense_level' => $offense, 'rate_snapshot' => $rate]);
                }
                $overdue++;
            });

        $this->info("Processed {$expired} expired approval(s), {$dueSoon} due-soon reminder(s), and {$overdue} overdue custody record(s).");

        return self::SUCCESS;
    }
}
