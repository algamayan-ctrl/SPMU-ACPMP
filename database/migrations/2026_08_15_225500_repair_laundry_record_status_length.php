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

        DB::statement("
            ALTER TABLE `laundry_records`
            MODIFY `status` VARCHAR(64)
            NOT NULL
            DEFAULT 'PENDING'
        ");
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
