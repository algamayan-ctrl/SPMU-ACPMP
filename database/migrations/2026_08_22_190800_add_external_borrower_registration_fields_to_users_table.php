<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Existing CSPC accounts are INTERNAL by default.
         */
        if (! Schema::hasColumn('users', 'borrower_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->string('borrower_type', 20)
                    ->default('INTERNAL')
                    ->index();
            });
        }

        /*
         * Existing institutional accounts do not require the
         * separate external-borrower verification process.
         */
        if (! Schema::hasColumn('users', 'borrower_verification_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->string('borrower_verification_status', 30)
                    ->default('NOT_REQUIRED')
                    ->index();
            });
        }

        if (! Schema::hasColumn('users', 'organization_name')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->string('organization_name')
                    ->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'organization_address')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->text('organization_address')
                    ->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'borrower_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->timestamp('borrower_verified_at')
                    ->nullable();
            });
        }

        /*
         * The actual verifier relationship will be used by
         * the SPMU verification workflow in Phase 2.
         */
        if (! Schema::hasColumn('users', 'borrower_verified_by_user_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table
                    ->unsignedBigInteger('borrower_verified_by_user_id')
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        $columns = [
            'borrower_verified_by_user_id',
            'borrower_verified_at',
            'organization_address',
            'organization_name',
            'borrower_verification_status',
            'borrower_type',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};