<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Production-safe reference data. These rows are required for ICTU to
         * create Laundry Worker accounts without requiring a separate seeder run.
         */
        $now = now();

        DB::table('roles')->updateOrInsert(
            ['role_code' => 'LAUNDRY'],
            [
                'role_name' => 'Laundry Service',
                'active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $institutionId = DB::table('organizational_units')
            ->where('unit_code', 'CSPC')
            ->value('id');

        DB::table('organizational_units')->updateOrInsert(
            ['unit_code' => 'LAUNDRY'],
            [
                'parent_unit_id' => $institutionId,
                'unit_name' => 'Laundry Service Area',
                'unit_type' => 'OPERATIONAL_UNIT',
                'active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        if (! Schema::hasTable('laundry_jobs')) {
            Schema::create('laundry_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custody_transaction_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('generated_document_id')
                ->nullable()
                ->constrained('generated_documents')
                ->nullOnDelete();
            $table->foreignId('latest_evidence_submission_id')
                ->nullable()
                ->constrained('evidence_submissions')
                ->nullOnDelete();
            $table->foreignId('form_verified_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 40)
                ->default('FOR_LAUNDRY')
                ->index();
            $table->string('worker_name')->nullable();
            $table->timestamp('worker_received_at')->nullable();
            $table->timestamp('worker_completed_at')->nullable();
            $table->text('worker_remarks')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('released_to_borrower_at')->nullable();
            $table->timestamp('form_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('laundry_job_lines')) {
            Schema::create('laundry_job_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laundry_job_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('custody_line_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->decimal('issued_quantity', 12, 3);
            $table->decimal('received_quantity', 12, 3)->nullable();
            $table->string('issue_type', 40)->nullable();
            $table->decimal('affected_quantity', 12, 3)->default(0);
            $table->decimal('completed_quantity', 12, 3)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['laundry_job_id', 'issue_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_job_lines');
        Schema::dropIfExists('laundry_jobs');

        /*
         * Do not delete the LAUNDRY role/unit here. They may already be
         * referenced by institutional accounts after this migration runs.
         */
    }
};
