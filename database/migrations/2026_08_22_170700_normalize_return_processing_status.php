<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custody_transactions')
            ->where('status', 'PARTIALLY_RETURNED')
            ->update([
                'status' => 'RETURN_PROCESSING',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: PARTIALLY_RETURNED is retired.
    }
};
