<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custody_transactions')) {
            return;
        }

        if (! Schema::hasColumn('custody_transactions', 'pickup_expires_at')) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->timestamp('pickup_expires_at')->nullable()->after('scheduled_release_at')->index();
            });
        }

        if (! Schema::hasColumn('custody_transactions', 'pickup_scheduled_by_user_id')) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->foreignId('pickup_scheduled_by_user_id')
                    ->nullable()
                    ->after('pickup_expires_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('custody_transactions', 'pickup_scheduled_at')) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->timestamp('pickup_scheduled_at')->nullable()->after('pickup_scheduled_by_user_id');
            });
        }
    }

    public function down(): void
    {
        /* Intentionally non-destructive: preserve operational pickup history. */
    }
};
