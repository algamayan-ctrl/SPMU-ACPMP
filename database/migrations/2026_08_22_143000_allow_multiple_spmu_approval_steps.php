<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Final SPMU workflow:
         *
         * Sequence 1 = SPMU Action Officer verification
         * Sequence 2 = SPMU Head/Admin final decision
         *
         * The old schema allowed only one SPMU stage per request version.
         * We retain the existing unique(request_version_id, sequence_no)
         * constraint, which safely allows these two distinct steps.
         */
        Schema::table('approval_steps', function (Blueprint $table): void {
            $table->dropUnique(
                'approval_steps_request_version_id_stage_code_unique'
            );
        });
    }

    public function down(): void
    {
        /*
         * Do not restore the deprecated unique
         * (request_version_id, stage_code) constraint because finalized
         * records can legitimately contain two SPMU steps.
         */
    }
};