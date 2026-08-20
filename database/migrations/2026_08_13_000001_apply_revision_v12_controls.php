<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Revision v12 compatibility migration
|--------------------------------------------------------------------------
|
| This migration may have been partially applied on databases created during
| development before Laravel recorded it in the migrations table. Every schema
| operation is therefore guarded so rerunning `php artisan migrate` is safe.
|
| The legacy signature columns are retained only for historical compatibility.
| The active workflow does not create electronic signatures.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('users')
            && ! Schema::hasColumn('users', 'access_classification')
        ) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('access_classification', 40)
                    ->default('BORROWER_ONLY')
                    ->after('account_status')
                    ->index();
            });
        }

        if (! Schema::hasTable('temporary_delegations')) {
            Schema::create('temporary_delegations', function (Blueprint $table): void {
                $table->id();
                $table->string('office_role', 20)->index();
                $table->foreignId('absent_head_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('delegate_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('recorded_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->string('authority_reference');
                $table->text('reason');
                $table->timestamp('effective_from')->index();
                $table->timestamp('effective_to')->index();
                $table->string('status', 30)->default('ACTIVE')->index();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->text('revocation_reason')->nullable();
                $table->timestamps();
            });
        }

        if (
            Schema::hasTable('request_items')
            && ! Schema::hasColumn('request_items', 'use_location')
        ) {
            Schema::table('request_items', function (Blueprint $table): void {
                $table->string('use_location', 20)
                    ->default('ON_CAMPUS')
                    ->after('approved_quantity')
                    ->index();
            });
        }

        if (
            Schema::hasTable('request_versions')
            && ! Schema::hasColumn('request_versions', 'represents_student_activity')
        ) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->boolean('represents_student_activity')
                    ->default(false)
                    ->after('return_due_at');
            });
        }

        if (
            Schema::hasTable('approval_steps')
            && ! Schema::hasColumn('approval_steps', 'temporary_delegation_id')
        ) {
            Schema::table('approval_steps', function (Blueprint $table): void {
                $table->foreignId('temporary_delegation_id')
                    ->nullable()
                    ->constrained('temporary_delegations')
                    ->nullOnDelete();
            });
        }

        if (
            Schema::hasTable('custody_lines')
            && ! Schema::hasColumn('custody_lines', 'item_status')
        ) {
            Schema::table('custody_lines', function (Blueprint $table): void {
                $table->string('item_status', 40)
                    ->default('CONFIRMED')
                    ->after('returned_quantity')
                    ->index();
            });
        }

        if (
            Schema::hasTable('custody_lines')
            && ! Schema::hasColumn('custody_lines', 'compliance_status')
        ) {
            Schema::table('custody_lines', function (Blueprint $table): void {
                $table->string('compliance_status', 50)
                    ->nullable()
                    ->after('item_status')
                    ->index();
            });
        }

        $this->addGatePassLegacyColumns();
        $this->addCustodyLegacyLaundryColumns();

        if (! Schema::hasTable('early_return_requests')) {
            Schema::create('early_return_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('early_return_no', 60)->unique();
                $table->foreignId('custody_transaction_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('proposed_return_at');
                $table->text('reason')->nullable();
                $table->string('status', 30)
                    ->default('REQUESTED')
                    ->index();
                $table->timestamp('requested_at');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('early_return_request_lines')) {
            Schema::create('early_return_request_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('early_return_request_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignId('custody_line_id')
                    ->constrained()
                    ->restrictOnDelete();
                $table->decimal('proposed_quantity', 12, 3);
                $table->timestamps();

                $table->unique(
                    ['early_return_request_id', 'custody_line_id'],
                    'err_lines_request_custody_unique'
                );
            });
        }

        if (Schema::hasTable('payments')) {
            /*
             * These three columns existed in the original schema and are made
             * nullable by this revision. Reapplying `change()` is safe.
             */
            if (
                Schema::hasColumn('payments', 'official_receipt_no')
                && Schema::hasColumn('payments', 'receipt_date')
                && Schema::hasColumn('payments', 'amount')
            ) {
                Schema::table('payments', function (Blueprint $table): void {
                    $table->string('official_receipt_no')->nullable()->change();
                    $table->date('receipt_date')->nullable()->change();
                    $table->decimal('amount', 12, 2)->nullable()->change();
                });
            }

            if (! Schema::hasColumn('payments', 'submitted_at')) {
                Schema::table('payments', function (Blueprint $table): void {
                    $table->timestamp('submitted_at')->nullable();
                });
            }

            if (! Schema::hasColumn('payments', 'verification_remarks')) {
                Schema::table('payments', function (Blueprint $table): void {
                    $table->text('verification_remarks')->nullable();
                });
            }

            if (! Schema::hasColumn('payments', 'rejection_reason')) {
                Schema::table('payments', function (Blueprint $table): void {
                    $table->text('rejection_reason')->nullable();
                });
            }
        }
    }

    private function addGatePassLegacyColumns(): void
    {
        if (! Schema::hasTable('gate_passes')) {
            return;
        }

        $columns = [
            'prepared_verified_by_user_id',
            'prepared_verifier_signature_snapshot_id',
            'prepared_verified_at',
            'approved_by_user_id',
            'approver_signature_snapshot_id',
            'temporary_delegation_id',
            'approved_at',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('gate_passes', $column)) {
                continue;
            }

            Schema::table('gate_passes', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'prepared_verified_by_user_id' =>
                        $table->foreignId($column)
                            ->nullable()
                            ->constrained('users')
                            ->nullOnDelete(),

                    'prepared_verifier_signature_snapshot_id' =>
                        $table->foreignId($column)
                            ->nullable()
                            ->constrained('signature_snapshots')
                            ->nullOnDelete(),

                    'prepared_verified_at' =>
                        $table->timestamp($column)->nullable(),

                    'approved_by_user_id' =>
                        $table->foreignId($column)
                            ->nullable()
                            ->constrained('users')
                            ->nullOnDelete(),

                    'approver_signature_snapshot_id' =>
                        $table->foreignId($column)
                            ->nullable()
                            ->constrained('signature_snapshots')
                            ->nullOnDelete(),

                    'temporary_delegation_id' =>
                        $table->foreignId($column)
                            ->nullable()
                            ->constrained('temporary_delegations')
                            ->nullOnDelete(),

                    'approved_at' =>
                        $table->timestamp($column)->nullable(),
                };
            });
        }
    }

    private function addCustodyLegacyLaundryColumns(): void
    {
        if (! Schema::hasTable('custody_transactions')) {
            return;
        }

        /*
         * Explicit short foreign-key names avoid MySQL's 64-character
         * identifier limit.
         */
        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_borrower_signature_snapshot_id'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->foreignId('laundry_borrower_signature_snapshot_id')
                    ->nullable();

                $table->foreign(
                    'laundry_borrower_signature_snapshot_id',
                    'ct_laundry_borrower_sig_fk'
                )
                    ->references('id')
                    ->on('signature_snapshots')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(
            'custody_transactions',
            'laundry_approved_by_user_id'
        )) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
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
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->foreignId('laundry_approver_signature_snapshot_id')
                    ->nullable();

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
            Schema::table('custody_transactions', function (Blueprint $table): void {
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
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->timestamp('laundry_approved_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally conservative.
         *
         * This migration existed in partially-applied development databases.
         * Rolling it back destructively could remove historical fields that are
         * still referenced by old records. `migrate:fresh` remains the correct
         * way to rebuild a disposable development database.
         */
    }
};
