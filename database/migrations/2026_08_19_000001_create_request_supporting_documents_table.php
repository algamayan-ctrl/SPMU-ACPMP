<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_supporting_documents')) {
            return;
        }

        Schema::create('request_supporting_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('borrowing_requests')->cascadeOnDelete();
            $table->foreignId('request_version_id')->constrained('request_versions')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->unsignedInteger('version_no')->default(1);
            $table->foreignId('stored_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('verification_status', 40)->default('PENDING_VERIFICATION');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_remarks')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['request_version_id', 'document_type', 'version_no'],
                'request_supporting_documents_version_unique'
            );
            $table->index(
                ['request_id', 'document_type', 'is_current'],
                'request_supporting_documents_current_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_supporting_documents');
    }
};
