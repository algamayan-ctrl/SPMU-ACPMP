<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_supporting_documents')) {
            return;
        }

        /*
         * The original supporting-document migration was marked
         * as ran even when an older version of this table already
         * existed. Repair the existing table in place instead of
         * dropping/recreating it.
         */

        if (! Schema::hasColumn('request_supporting_documents', 'request_id')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->foreignId('request_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('borrowing_requests')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'version_no')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->unsignedInteger('version_no')
                    ->default(1)
                    ->after('document_type');
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'verification_status')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->string('verification_status', 40)
                    ->default('PENDING_VERIFICATION')
                    ->after('uploaded_at');
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'verified_by_user_id')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->foreignId('verified_by_user_id')
                    ->nullable()
                    ->after('verification_status')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'verified_at')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->timestamp('verified_at')
                    ->nullable()
                    ->after('verified_by_user_id');
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'verification_remarks')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->text('verification_remarks')
                    ->nullable()
                    ->after('verified_at');
            });
        }

        if (! Schema::hasColumn('request_supporting_documents', 'is_current')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table
                    ->boolean('is_current')
                    ->default(true)
                    ->after('verification_remarks');
            });
        }

        /*
         * Backfill request_id from the owning request version.
         * This is harmless on an empty table and protects other
         * environments that may already contain legacy rows.
         */
        DB::statement(
            'UPDATE request_supporting_documents rsd
             INNER JOIN request_versions rv
                 ON rv.id = rsd.request_version_id
             SET rsd.request_id = rv.request_id
             WHERE rsd.request_id IS NULL'
        );

        /*
         * Legacy rows, if any, become version 1/current unless
         * their existing superseded_at value says otherwise.
         */
        DB::table('request_supporting_documents')
            ->whereNull('version_no')
            ->update([
                'version_no' => 1,
            ]);

        DB::table('request_supporting_documents')
            ->whereNotNull('superseded_at')
            ->update([
                'is_current' => false,
            ]);

        /*
         * Add the indexes expected by the current document
         * versioning implementation.
         */
        $indexes = collect(
            Schema::getIndexes('request_supporting_documents')
        )->pluck('name');

        if (! $indexes->contains('request_supporting_documents_version_unique')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table->unique(
                    [
                        'request_version_id',
                        'document_type',
                        'version_no',
                    ],
                    'request_supporting_documents_version_unique'
                );
            });
        }

        $indexes = collect(
            Schema::getIndexes('request_supporting_documents')
        )->pluck('name');

        if (! $indexes->contains('request_supporting_documents_current_index')) {
            Schema::table('request_supporting_documents', function (Blueprint $table): void {
                $table->index(
                    [
                        'request_id',
                        'document_type',
                        'is_current',
                    ],
                    'request_supporting_documents_current_index'
                );
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * This is a repair migration for an already-existing
         * production-style table. Automatic rollback must not
         * discard supporting-document history.
         */
    }
};