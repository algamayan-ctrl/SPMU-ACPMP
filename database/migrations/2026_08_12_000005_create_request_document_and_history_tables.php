<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_versions', function (Blueprint $table) {
            $table->foreignId('borrower_signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('accuracy_certified')->default(false);
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->foreignId('signature_snapshot_id')->nullable()->constrained('signature_snapshots')->nullOnDelete();
        });

        Schema::create('request_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('borrowing_requests')->cascadeOnDelete();
            $table->foreignId('request_version_id')->nullable()->constrained('request_versions')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 60)->nullable();
            $table->string('to_status', 60)->index();
            $table->text('reason')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('request_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('borrowing_requests')->cascadeOnDelete();
            $table->foreignId('request_version_id')->nullable()->constrained('request_versions')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('phase', 40);
            $table->text('reason');
            $table->timestamp('cancelled_at');
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50)->index();
            $table->unsignedInteger('template_version')->default(1);
            $table->string('template_name');
            $table->text('content_template')->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_type', 'template_version']);
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->foreignId('request_version_id')->nullable()->constrained('request_versions')->nullOnDelete();
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('document_no', 80)->unique();
            $table->string('document_type', 50)->index();
            $table->unsignedInteger('version_no')->default(1);
            $table->string('sha256', 64)->nullable()->index();
            $table->string('status', 30)->default('DRAFT')->index();
            $table->timestamp('generated_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('document_approvals', function (Blueprint $table) {
            $table->foreignId('generated_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_step_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('display_order');
            $table->primary(['generated_document_id', 'approval_step_id']);
        });

        Schema::create('download_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('downloaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('downloaded_at');
            $table->string('integrity_hash', 64);
            $table->string('origin_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('stored_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('upload_mode', 30);
            $table->text('fallback_reason')->nullable();
            $table->timestamp('borrower_notified_at')->nullable();
            $table->timestamp('submitted_at');
            $table->string('verification_status', 30)->default('PENDING_VERIFICATION')->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_submissions');
        Schema::dropIfExists('download_events');
        Schema::dropIfExists('document_approvals');
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('request_cancellations');
        Schema::dropIfExists('request_status_histories');

        Schema::table('approval_steps', fn (Blueprint $table) => $table->dropConstrainedForeignId('signature_snapshot_id'));
        Schema::table('request_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('borrower_signature_snapshot_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn('accuracy_certified');
        });
    }
};
