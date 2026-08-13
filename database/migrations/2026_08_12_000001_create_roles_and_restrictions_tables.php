<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('role_code', 30)->unique();
            $table->string('role_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->primary(['user_id', 'role_id', 'assigned_at']);
            $table->index(['role_id', 'revoked_at']);
        });

        Schema::create('borrower_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('restriction_type', 50);
            $table->text('reason');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 30)->default('ACTIVE')->index();
            $table->foreignId('imposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lifted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['borrower_user_id', 'status', 'effective_to'], 'borrower_restriction_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrower_restrictions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
