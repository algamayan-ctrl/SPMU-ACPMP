<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->string('category_code', 40)->unique();
            $table->string('category_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code', 30)->unique();
            $table->string('unit_name');
            $table->unsignedTinyInteger('decimal_scale')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('unique_description');
            $table->text('specification')->nullable();
            $table->decimal('total_quantity', 12, 3)->default(0);
            $table->string('condition_code', 40)->default('SERVICEABLE');
            $table->boolean('borrowable')->default(true);
            $table->boolean('off_campus_allowed')->default(false);
            $table->boolean('laundry_required')->default(false);
            $table->boolean('provisional')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['unique_description', 'category_id'], 'inventory_item_description_category_uq');
            $table->index(['borrowable', 'active', 'condition_code']);
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_type', 50)->index();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->index();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inventory_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->decimal('quantity', 12, 3);
            $table->timestamp('effective_from')->nullable()->index();
            $table->timestamp('effective_to')->nullable()->index();
            $table->decimal('before_quantity', 12, 3)->nullable();
            $table->decimal('after_quantity', 12, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transaction_lines');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('inventory_categories');
    }
};
