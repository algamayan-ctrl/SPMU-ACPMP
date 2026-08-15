<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laundry_records')) {
            return;
        }

        /*
         * Laundry workflow uses statuses longer than VARCHAR(30),
         * particularly:
         *
         * EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK
         *
         * Increase the column to VARCHAR(64).
         */
        DB::statement("
            ALTER TABLE `laundry_records`
            MODIFY `status` VARCHAR(64)
            NOT NULL
            DEFAULT 'PENDING'
        ");
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * Do not shrink this field again because valid workflow
         * statuses exceed 30 characters.
         */
    }
};