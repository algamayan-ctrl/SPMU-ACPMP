<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_versions')) {
            return;
        }

        if (! Schema::hasColumn('request_versions', 'schedule_date')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->date('schedule_date')
                    ->nullable()
                    ->after('location')
                    ->index();
            });
        }

        if (! Schema::hasColumn('request_versions', 'return_date')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->date('return_date')
                    ->nullable()
                    ->after('schedule_date')
                    ->index();
            });
        }

        /*
         * Preserve all existing request-version records.
         *
         * Backfill the new DATE columns from the historical
         * timestamp columns without modifying the old values.
         */
        DB::table('request_versions')
            ->whereNull('schedule_date')
            ->whereNotNull('needed_from')
            ->update([
                'schedule_date' => DB::raw('DATE(needed_from)'),
            ]);

        DB::table('request_versions')
            ->whereNull('return_date')
            ->whereNotNull('return_due_at')
            ->update([
                'return_date' => DB::raw('DATE(return_due_at)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('request_versions')) {
            return;
        }

        if (Schema::hasColumn('request_versions', 'return_date')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->dropColumn('return_date');
            });
        }

        if (Schema::hasColumn('request_versions', 'schedule_date')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->dropColumn('schedule_date');
            });
        }
    }
};
