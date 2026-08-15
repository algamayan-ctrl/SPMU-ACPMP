<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Create the Early Return header table if it is missing.
         */
        if (! Schema::hasTable('early_return_requests')) {
            Schema::create('early_return_requests', function (Blueprint $table) {
                $table->id();

                $table->string('early_return_no', 60)
                    ->unique();

                $table->foreignId('custody_transaction_id')
                    ->constrained('custody_transactions')
                    ->cascadeOnDelete();

                $table->foreignId('requested_by_user_id')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp('proposed_return_at');

                $table->text('reason')
                    ->nullable();

                $table->string('status', 30)
                    ->default('REQUESTED')
                    ->index();

                $table->timestamp('requested_at');

                $table->timestamp('completed_at')
                    ->nullable();

                $table->timestamps();
            });
        }

        /*
         * Create the individual Early Return item lines
         * if the table is missing.
         */
        if (! Schema::hasTable('early_return_request_lines')) {
            Schema::create('early_return_request_lines', function (Blueprint $table) {
                $table->id();

                $table->foreignId('early_return_request_id')
                    ->constrained('early_return_requests')
                    ->cascadeOnDelete();

                $table->foreignId('custody_line_id')
                    ->constrained('custody_lines')
                    ->restrictOnDelete();

                $table->decimal(
                    'proposed_quantity',
                    12,
                    3
                );

                $table->timestamps();

                $table->unique(
                    [
                        'early_return_request_id',
                        'custody_line_id',
                    ],
                    'err_lines_request_custody_unique'
                );
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally left non-destructive.
         *
         * This is a repair migration. We do not want rollback
         * to accidentally delete existing Early Return records.
         */
    }
};