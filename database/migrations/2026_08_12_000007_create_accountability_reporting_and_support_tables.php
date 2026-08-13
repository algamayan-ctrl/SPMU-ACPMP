<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('penalty_type', 50)->index();
            $table->string('calculation_method', 40)->default('MANUAL');
            $table->decimal('rate', 12, 2)->nullable();
            $table->string('rate_unit', 30)->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 30)->default('DRAFT');
            $table->timestamps();
        });

        Schema::create('overdue_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tariff_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('grace_expires_at');
            $table->timestamp('overdue_started_at')->nullable();
            $table->unsignedTinyInteger('offense_level')->default(1);
            $table->decimal('rate_snapshot', 12, 2)->nullable();
            $table->decimal('accrued_amount', 12, 2)->default(0);
            $table->string('sanction_type', 50)->nullable();
            $table->string('status', 30)->default('GRACE')->index();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_no', 60)->unique();
            $table->foreignId('custody_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('incident_type', 40)->index();
            $table->timestamp('reported_at');
            $table->string('police_blotter_reference')->nullable();
            $table->decimal('appraisal_amount', 12, 2)->nullable();
            $table->string('rslddp_reference')->nullable();
            $table->string('status', 40)->default('OPEN')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custody_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('observed_condition', 60);
            $table->string('disposition_state', 40);
            $table->decimal('assessed_value', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('custody_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('overdue_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('penalty_type', 50);
            $table->unsignedTinyInteger('offense_level')->nullable();
            $table->text('basis');
            $table->decimal('rate_snapshot', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('ASSESSED')->index();
            $table->timestamp('assessed_at');
            $table->timestamps();
        });

        Schema::create('billing_statements', function (Blueprint $table) {
            $table->id();
            $table->string('billing_no', 60)->unique();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('responsible_spmu_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->string('status', 40)->default('ISSUED')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('penalty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_type', 40);
            $table->text('description');
            $table->text('basis')->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_statement_id')->constrained()->restrictOnDelete();
            $table->foreignId('evidence_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('official_receipt_no');
            $table->date('receipt_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 40)->default('PENDING_VERIFICATION')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('borrower_restrictions', function (Blueprint $table) {
            $table->foreignId('penalty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_statement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('kpi_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('borrowing_requests')->nullOnDelete();
            $table->foreignId('custody_id')->nullable()->constrained('custody_transactions')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('process_code', 50)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->unsignedInteger('correct_count')->nullable();
            $table->unsignedInteger('total_count')->nullable();
            $table->decimal('output_count', 12, 3)->nullable();
            $table->decimal('input_value', 12, 3)->nullable();
            $table->string('input_unit', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_observations');
        Schema::table('borrower_restrictions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_id');
            $table->dropConstrainedForeignId('billing_statement_id');
            $table->dropConstrainedForeignId('incident_id');
        });
        Schema::dropIfExists('payments');
        Schema::dropIfExists('billing_lines');
        Schema::dropIfExists('billing_statements');
        Schema::dropIfExists('penalties');
        Schema::dropIfExists('incident_lines');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('overdue_cases');
        Schema::dropIfExists('tariff_rules');
    }
};
