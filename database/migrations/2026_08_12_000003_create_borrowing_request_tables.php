<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 50)->unique();
            $table->foreignId('borrower_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accountable_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->string('status', 60)->default('DRAFT')->index();
            $table->timestamp('final_approved_at')->nullable();
            $table->timestamp('download_deadline_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('request_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('borrowing_requests')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('purpose_event');
            $table->string('location');
            $table->timestamp('needed_from')->index();
            $table->timestamp('return_due_at')->index();
            $table->string('student_organization')->nullable();
            $table->string('represented_program_department')->nullable();
            $table->string('represented_year_level', 40)->nullable();
            $table->text('event_details')->nullable();
            $table->boolean('off_campus')->default(false);
            $table->text('remarks')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'version_no']);
        });

        Schema::create('request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_version_id')->constrained('request_versions')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('description_snapshot');
            $table->string('unit_snapshot', 80);
            $table->decimal('requested_quantity', 12, 3);
            $table->decimal('approved_quantity', 12, 3)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['request_version_id', 'inventory_item_id']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_version_id')->constrained('request_versions')->cascadeOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage_code', 20);
            $table->unsignedTinyInteger('sequence_no');
            $table->timestamp('received_at')->nullable();
            $table->string('decision', 40)->default('PENDING');
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['request_version_id', 'stage_code']);
            $table->unique(['request_version_id', 'sequence_no']);
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_item_id')->unique()->constrained('request_items')->cascadeOnDelete();
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end')->index();
            $table->decimal('allocated_quantity', 12, 3);
            $table->decimal('released_quantity', 12, 3)->default(0);
            $table->decimal('restored_quantity', 12, 3)->default(0);
            $table->string('status', 40)->default('ACTIVE')->index();
            $table->timestamp('allocated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('request_items');
        Schema::dropIfExists('request_versions');
        Schema::dropIfExists('borrowing_requests');
    }
};
