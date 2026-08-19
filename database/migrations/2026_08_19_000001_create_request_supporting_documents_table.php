<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_supporting_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_version_id')
                ->constrained('request_versions')
                ->cascadeOnDelete();
            $table->foreignId('stored_file_id')
                ->constrained('stored_files')
                ->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('document_type', 40)->index();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(
                ['request_version_id', 'document_type', 'status'],
                'request_supporting_docs_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_supporting_documents');
    }
};
