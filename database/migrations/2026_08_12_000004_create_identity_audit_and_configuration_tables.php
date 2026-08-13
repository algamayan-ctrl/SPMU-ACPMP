<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('full_name');
            $table->timestamp('last_login_at')->nullable()->after('account_status');
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('storage_path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64)->index();
            $table->string('classification', 50)->default('PROTECTED');
            $table->timestamps();
        });

        Schema::create('user_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 30)->default('ACTIVE')->index();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('signature_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_signature_id')->constrained('user_signatures')->restrictOnDelete();
            $table->foreignId('signer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('snapshot_file_id')->constrained('stored_files')->restrictOnDelete();
            $table->string('signer_name');
            $table->string('signer_role', 40)->nullable();
            $table->string('purpose_code', 60);
            $table->string('sha256', 64);
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->json('value_json')->nullable();
            $table->string('data_type', 30)->default('STRING');
            $table->string('group_code', 50)->default('GENERAL')->index();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('configuration_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before_value_json')->nullable();
            $table->json('after_value_json');
            $table->text('reason');
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_code', 80)->index();
            $table->string('record_type', 100)->index();
            $table->unsignedBigInteger('record_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->string('origin_ip', 45)->nullable();
            $table->text('reason')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->uuid('correlation_id')->index();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_code', 80);
            $table->string('channel', 20);
            $table->unsignedInteger('template_version')->default(1);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 30)->default('ACTIVE');
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event_code', 'channel', 'template_version'], 'notification_template_version_uq');
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_code', 80)->index();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload_snapshot_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->index();
            $table->string('address_snapshot')->nullable();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('provider')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('delivery_status', 30)->default('PENDING')->index();
            $table->text('provider_response')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('technical_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation_type', 50)->index();
            $table->string('status', 30)->index();
            $table->string('reference')->nullable();
            $table->text('details')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_operations');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('configuration_changes');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('signature_snapshots');
        Schema::dropIfExists('user_signatures');
        Schema::dropIfExists('stored_files');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['designation', 'last_login_at']);
        });
    }
};
