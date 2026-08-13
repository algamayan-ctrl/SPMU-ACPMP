<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 60)->unique();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('before_quantity', 12, 3);
            $table->decimal('after_quantity', 12, 3);
            $table->text('reason');
            $table->string('status', 30)->default('APPROVED');
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('custody_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('custody_no', 60)->unique();
            $table->foreignId('request_id')->unique()->constrained('borrowing_requests')->restrictOnDelete();
            $table->foreignId('request_version_id')->constrained('request_versions')->restrictOnDelete();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('borrower_ack_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->string('status', 40)->default('PREPARING_RELEASE')->index();
            $table->timestamp('scheduled_release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('due_at')->index();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('custody_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained()->restrictOnDelete();
            $table->decimal('approved_quantity', 12, 3);
            $table->decimal('quantity_to_receive', 12, 3);
            $table->decimal('actual_released_quantity', 12, 3)->default(0);
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->string('release_condition', 40)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->timestamps();
            $table->unique(['custody_transaction_id', 'request_item_id']);
        });

        Schema::create('return_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 60)->unique();
            $table->foreignId('custody_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('return_type', 30)->default('NORMAL');
            $table->timestamp('received_at');
            $table->string('status', 30)->default('INSPECTED');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custody_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 12, 3);
            $table->string('condition_code', 40);
            $table->string('disposition_state', 40);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('custody_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('pass_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('bearer_name');
            $table->string('destination');
            $table->text('purpose');
            $table->string('guard_name')->nullable();
            $table->timestamp('guard_signed_at')->nullable();
            $table->string('status', 30)->default('PENDING')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('laundry_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('form_document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('worker_name')->nullable();
            $table->timestamp('worker_received_at')->nullable();
            $table->timestamp('worker_completed_at')->nullable();
            $table->decimal('cleaned_quantity', 12, 3)->default(0);
            $table->decimal('damaged_quantity', 12, 3)->default(0);
            $table->string('status', 30)->default('PENDING')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_records');
        Schema::dropIfExists('gate_passes');
        Schema::dropIfExists('return_lines');
        Schema::dropIfExists('return_transactions');
        Schema::dropIfExists('custody_lines');
        Schema::dropIfExists('custody_transactions');
        Schema::dropIfExists('inventory_adjustments');
    }
};
