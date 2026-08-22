<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laundry_jobs') || ! Schema::hasTable('laundry_job_lines') || ! Schema::hasTable('custody_lines')) {
            return;
        }

        DB::table('laundry_jobs')
            ->where('status', 'READY_FOR_PICKUP')
            ->update([
                'status' => 'READY_FOR_SPMU_RETURN',
                'latest_evidence_submission_id' => null,
                'form_verified_by_user_id' => null,
                'form_verified_at' => null,
            ]);

        $legacyJobs = DB::table('laundry_jobs')
            ->whereIn('status', ['FOR_SPMU_FINAL_CHECK', 'FORM_REPLACEMENT_REQUIRED'])
            ->get(['id', 'status', 'worker_completed_at']);

        foreach ($legacyJobs as $job) {
            $lines = DB::table('laundry_job_lines')
                ->join('custody_lines', 'custody_lines.id', '=', 'laundry_job_lines.custody_line_id')
                ->where('laundry_job_lines.laundry_job_id', $job->id)
                ->get([
                    'custody_lines.actual_released_quantity',
                    'custody_lines.returned_quantity',
                ]);

            $allPhysicallyAcceptedBySpmu = $lines->isNotEmpty()
                && $lines->every(
                    fn ($line) => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
                );

            $nextStatus = match (true) {
                $allPhysicallyAcceptedBySpmu => 'AWAITING_FINAL_FORM_UPLOAD',
                $job->status === 'FOR_SPMU_FINAL_CHECK' => 'READY_FOR_SPMU_RETURN',
                $job->worker_completed_at !== null => 'READY_FOR_SPMU_RETURN',
                default => 'IN_PROCESS',
            };

            DB::table('laundry_jobs')
                ->where('id', $job->id)
                ->update([
                    'status' => $nextStatus,
                    'latest_evidence_submission_id' => null,
                    'form_verified_by_user_id' => null,
                    'form_verified_at' => null,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('laundry_jobs')) {
            return;
        }

        DB::table('laundry_jobs')
            ->where('status', 'READY_FOR_SPMU_RETURN')
            ->update(['status' => 'READY_FOR_PICKUP']);

        DB::table('laundry_jobs')
            ->where('status', 'AWAITING_FINAL_FORM_UPLOAD')
            ->update(['status' => 'FOR_SPMU_FINAL_CHECK']);
    }
};
