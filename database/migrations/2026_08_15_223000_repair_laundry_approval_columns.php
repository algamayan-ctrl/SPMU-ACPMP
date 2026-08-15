<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * SPMU Head Laundry Form approval fields.
         *
         * These fields belong to custody_transactions but may be
         * missing when the older revision migration was only
         * partially applied.
         */

        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_approved_by_user_id'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table) {
                $table->foreignId('laundry_approved_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_approver_signature_snapshot_id'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table) {
                $table->foreignId(
                    'laundry_approver_signature_snapshot_id'
                )->nullable();

                $table->foreign(
                    'laundry_approver_signature_snapshot_id',
                    'ct_laundry_approver_sig_fk'
                )
                    ->references('id')
                    ->on('signature_snapshots')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_temporary_delegation_id'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table) {
                $table->foreignId('laundry_temporary_delegation_id')
                    ->nullable()
                    ->constrained('temporary_delegations')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_approved_at'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table) {
                $table->timestamp('laundry_approved_at')
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * This is a repair migration. We do not automatically
         * remove approval records during rollback.
         */
    }
};