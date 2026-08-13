<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('access_classification', 40)->default('BORROWER_ONLY')->after('account_status')->index();
        });

        Schema::create('temporary_delegations', function (Blueprint $table) {
            $table->id();
            $table->string('office_role', 20)->index();
            $table->foreignId('absent_head_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('authority_reference');
            $table->text('reason');
            $table->timestamp('effective_from')->index();
            $table->timestamp('effective_to')->index();
            $table->string('status', 30)->default('ACTIVE')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('request_items', function (Blueprint $table) {
            $table->string('use_location', 20)->default('ON_CAMPUS')->after('approved_quantity')->index();
        });

        Schema::table('request_versions', function (Blueprint $table) {
            $table->boolean('represents_student_activity')->default(false)->after('return_due_at');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->foreignId('temporary_delegation_id')->nullable()->constrained('temporary_delegations')->nullOnDelete();
        });

        Schema::table('custody_lines', function (Blueprint $table) {
            $table->string('item_status', 40)->default('CONFIRMED')->after('returned_quantity')->index();
            $table->string('compliance_status', 50)->nullable()->after('item_status')->index();
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->foreignId('prepared_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_verifier_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->timestamp('prepared_verified_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->foreignId('temporary_delegation_id')->nullable()->constrained('temporary_delegations')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        Schema::table('custody_transactions', function (Blueprint $table) {
            $table->foreignId('laundry_borrower_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->foreignId('laundry_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('laundry_approver_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->foreignId('laundry_temporary_delegation_id')->nullable()->constrained('temporary_delegations')->nullOnDelete();
            $table->timestamp('laundry_approved_at')->nullable();
        });

        Schema::create('early_return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('early_return_no', 60)->unique();
            $table->foreignId('custody_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('proposed_return_at');
            $table->text('reason')->nullable();
            $table->string('status', 30)->default('REQUESTED')->index();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('early_return_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('early_return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custody_line_id')->constrained()->restrictOnDelete();
            $table->decimal('proposed_quantity', 12, 3);
            $table->timestamps();
            $table->unique(['early_return_request_id', 'custody_line_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('official_receipt_no')->nullable()->change();
            $table->date('receipt_date')->nullable()->change();
            $table->decimal('amount', 12, 2)->nullable()->change();
            $table->timestamp('submitted_at')->nullable();
            $table->text('verification_remarks')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'verification_remarks', 'rejection_reason']);
        });
        Schema::dropIfExists('early_return_request_lines');
        Schema::dropIfExists('early_return_requests');
        Schema::table('custody_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('laundry_borrower_signature_snapshot_id');
            $table->dropConstrainedForeignId('laundry_approved_by_user_id');
            $table->dropConstrainedForeignId('laundry_approver_signature_snapshot_id');
            $table->dropConstrainedForeignId('laundry_temporary_delegation_id');
            $table->dropColumn('laundry_approved_at');
        });
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_verified_by_user_id');
            $table->dropConstrainedForeignId('prepared_verifier_signature_snapshot_id');
            $table->dropColumn('prepared_verified_at');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('approver_signature_snapshot_id');
            $table->dropConstrainedForeignId('temporary_delegation_id');
            $table->dropColumn('approved_at');
        });
        Schema::table('custody_lines', fn (Blueprint $table) => $table->dropColumn(['item_status', 'compliance_status']));
        Schema::table('approval_steps', fn (Blueprint $table) => $table->dropConstrainedForeignId('temporary_delegation_id'));
        Schema::table('request_items', fn (Blueprint $table) => $table->dropColumn('use_location'));
        Schema::table('request_versions', fn (Blueprint $table) => $table->dropColumn('represents_student_activity'));
        Schema::dropIfExists('temporary_delegations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('access_classification'));
    }
};
