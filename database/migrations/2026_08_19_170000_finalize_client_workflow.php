<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Dedicated Laundry Worker role.
         * The users.access_classification column is already a VARCHAR, so no
         * destructive schema change is needed for LAUNDRY_WORKER.
         */
        if (Schema::hasTable('roles')) {
            DB::table('roles')->updateOrInsert(
                ['role_code' => 'LAUNDRY'],
                [
                    'role_name' => 'Laundry Worker',
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        /*
         * Cancellation-after-reservation review.
         * Existing historical rows are treated as already confirmed.
         */
        if (Schema::hasTable('request_cancellations')) {
            if (! Schema::hasColumn('request_cancellations', 'status')) {
                Schema::table('request_cancellations', function (Blueprint $table): void {
                    $table->string('status', 30)->default('CONFIRMED')->after('reason')->index();
                });
            }
            if (! Schema::hasColumn('request_cancellations', 'requested_at')) {
                Schema::table('request_cancellations', function (Blueprint $table): void {
                    $table->timestamp('requested_at')->nullable()->after('status');
                });
            }
            if (! Schema::hasColumn('request_cancellations', 'reviewed_by_user_id')) {
                Schema::table('request_cancellations', function (Blueprint $table): void {
                    $table->foreignId('reviewed_by_user_id')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('request_cancellations', 'reviewed_at')) {
                Schema::table('request_cancellations', function (Blueprint $table): void {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
                });
            }
            if (! Schema::hasColumn('request_cancellations', 'decision_remarks')) {
                Schema::table('request_cancellations', function (Blueprint $table): void {
                    $table->text('decision_remarks')->nullable()->after('reviewed_at');
                });
            }

            DB::table('request_cancellations')
                ->whereNull('requested_at')
                ->update([
                    'requested_at' => DB::raw('COALESCE(cancelled_at, created_at)'),
                    'status' => 'CONFIRMED',
                ]);
        }

        /*
         * Pickup expiration and issued-record locking.
         */
        if (Schema::hasTable('custody_transactions')) {
            if (! Schema::hasColumn('custody_transactions', 'pickup_expired_at')) {
                Schema::table('custody_transactions', function (Blueprint $table): void {
                    $table->timestamp('pickup_expired_at')->nullable()->after('pickup_expires_at')->index();
                });
            }
            if (! Schema::hasColumn('custody_transactions', 'issuance_confirmed_at')) {
                Schema::table('custody_transactions', function (Blueprint $table): void {
                    $table->timestamp('issuance_confirmed_at')->nullable()->after('released_at');
                });
            }
            if (! Schema::hasColumn('custody_transactions', 'issuance_locked_at')) {
                Schema::table('custody_transactions', function (Blueprint $table): void {
                    $table->timestamp('issuance_locked_at')->nullable()->after('issuance_confirmed_at')->index();
                });
            }
            if (! Schema::hasColumn('custody_transactions', 'issuance_remarks')) {
                Schema::table('custody_transactions', function (Blueprint $table): void {
                    $table->text('issuance_remarks')->nullable()->after('issuance_locked_at');
                });
            }
        }

        /*
         * Return draft -> confirm -> read-only lifecycle.
         */
        if (Schema::hasTable('return_transactions')) {
            if (! Schema::hasColumn('return_transactions', 'confirmed_by_user_id')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->foreignId('confirmed_by_user_id')->nullable()->after('received_by_user_id')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('return_transactions', 'confirmed_at')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->timestamp('confirmed_at')->nullable()->after('received_at');
                });
            }
            if (! Schema::hasColumn('return_transactions', 'locked_at')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->timestamp('locked_at')->nullable()->after('confirmed_at')->index();
                });
            }
            if (! Schema::hasColumn('return_transactions', 'reopened_by_user_id')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->foreignId('reopened_by_user_id')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('return_transactions', 'reopened_at')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->timestamp('reopened_at')->nullable()->after('reopened_by_user_id');
                });
            }
            if (! Schema::hasColumn('return_transactions', 'reopen_reason')) {
                Schema::table('return_transactions', function (Blueprint $table): void {
                    $table->text('reopen_reason')->nullable()->after('reopened_at');
                });
            }

            DB::table('return_transactions')
                ->where('status', 'INSPECTED')
                ->whereNull('confirmed_at')
                ->update([
                    'confirmed_at' => DB::raw('received_at'),
                    'locked_at' => DB::raw('received_at'),
                ]);
        }

        if (Schema::hasTable('return_lines')) {
            if (! Schema::hasColumn('return_lines', 'supporting_evidence_file_id')) {
                Schema::table('return_lines', function (Blueprint $table): void {
                    $table->foreignId('supporting_evidence_file_id')->nullable()->after('disposition_state')->constrained('stored_files')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('return_lines', 'police_blotter_reference')) {
                Schema::table('return_lines', function (Blueprint $table): void {
                    $table->string('police_blotter_reference')->nullable()->after('supporting_evidence_file_id');
                });
            }
        }

        /*
         * Fully accomplished physical Gate Pass evidence returned to SPMU.
         */
        if (Schema::hasTable('gate_passes')) {
            if (! Schema::hasColumn('gate_passes', 'accomplished_file_id')) {
                Schema::table('gate_passes', function (Blueprint $table): void {
                    $table->foreignId('accomplished_file_id')->nullable()->after('pass_document_id')->constrained('stored_files')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('gate_passes', 'uploaded_by_user_id')) {
                Schema::table('gate_passes', function (Blueprint $table): void {
                    $table->foreignId('uploaded_by_user_id')->nullable()->after('accomplished_file_id')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('gate_passes', 'uploaded_at')) {
                Schema::table('gate_passes', function (Blueprint $table): void {
                    $table->timestamp('uploaded_at')->nullable()->after('uploaded_by_user_id');
                });
            }
            if (! Schema::hasColumn('gate_passes', 'verification_remarks')) {
                Schema::table('gate_passes', function (Blueprint $table): void {
                    $table->text('verification_remarks')->nullable()->after('verified_at');
                });
            }
        }

        /*
         * Laundry Worker structured processing + accomplished physical form.
         */
        if (Schema::hasTable('laundry_records')) {
            if (! Schema::hasColumn('laundry_records', 'worker_user_id')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->foreignId('worker_user_id')->nullable()->after('verified_by_user_id')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('laundry_records', 'quantity_received')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->decimal('quantity_received', 12, 3)->nullable()->after('worker_completed_at');
                });
            }
            if (! Schema::hasColumn('laundry_records', 'received_condition')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->string('received_condition', 60)->nullable()->after('quantity_received');
                });
            }
            if (! Schema::hasColumn('laundry_records', 'remarks')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->text('remarks')->nullable()->after('damaged_quantity');
                });
            }
            if (! Schema::hasColumn('laundry_records', 'accomplished_file_id')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->foreignId('accomplished_file_id')->nullable()->after('form_document_id')->constrained('stored_files')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('laundry_records', 'uploaded_by_user_id')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->foreignId('uploaded_by_user_id')->nullable()->after('accomplished_file_id')->constrained('users')->nullOnDelete();
                });
            }
            if (! Schema::hasColumn('laundry_records', 'uploaded_at')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->timestamp('uploaded_at')->nullable()->after('uploaded_by_user_id');
                });
            }
            if (! Schema::hasColumn('laundry_records', 'verification_remarks')) {
                Schema::table('laundry_records', function (Blueprint $table): void {
                    $table->text('verification_remarks')->nullable()->after('verified_at');
                });
            }
        }

        /*
         * Academic period configuration.
         */
        if (! Schema::hasTable('academic_periods')) {
            Schema::create('academic_periods', function (Blueprint $table): void {
                $table->id();
                $table->string('academic_year', 20)->index();
                $table->string('term_code', 30)->index();
                $table->string('term_name');
                $table->date('start_date')->index();
                $table->date('end_date')->index();
                $table->string('status', 20)->default('UPCOMING')->index();
                $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['academic_year', 'term_code'], 'academic_period_year_term_unique');
            });
        }

        /*
         * Configurable sanction recommendation rules.
         * Nothing here automatically imposes a sanction.
         */
        if (! Schema::hasTable('sanction_rules')) {
            Schema::create('sanction_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('offense_no')->index();
                $table->string('sanction_code', 50);
                $table->string('sanction_label');
                $table->string('duration_mode', 40)->default('MANUAL');
                $table->string('status', 20)->default('DRAFT')->index();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('borrower_violations')) {
            Schema::create('borrower_violations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('custody_transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('academic_period_id')->nullable()->constrained()->nullOnDelete();
                $table->string('violation_code', 50)->index();
                $table->json('details_json')->nullable();
                $table->string('status', 30)->default('PENDING_REVIEW')->index();
                $table->timestamp('detected_at');
                $table->foreignId('detected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_remarks')->nullable();
                $table->timestamps();
                $table->unique(['custody_transaction_id', 'violation_code'], 'borrower_violation_custody_code_unique');
            });
        }

        if (! Schema::hasTable('sanctions')) {
            Schema::create('sanctions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('borrower_violation_id')->unique()->constrained('borrower_violations')->restrictOnDelete();
                $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('academic_period_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('sanction_rule_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedSmallInteger('offense_no');
                $table->string('sanction_code', 50);
                $table->string('sanction_label');
                $table->timestamp('effective_from');
                $table->timestamp('effective_to')->nullable();
                $table->string('status', 30)->default('ACTIVE')->index();
                $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
                $table->timestamp('confirmed_at');
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('borrower_restrictions') && ! Schema::hasColumn('borrower_restrictions', 'sanction_id')) {
            Schema::table('borrower_restrictions', function (Blueprint $table): void {
                $table->foreignId('sanction_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally conservative. This migration finalizes a workflow while
         * preserving historical records. Rollback should not silently erase
         * sanctions, academic periods, evidence, or operational audit data.
         */
    }
};
