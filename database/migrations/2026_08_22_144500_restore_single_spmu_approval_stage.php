<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table): void {
            $table->unique(
                ['request_version_id', 'stage_code'],
                'approval_steps_request_version_id_stage_code_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table): void {
            $table->dropUnique('approval_steps_request_version_id_stage_code_unique');
        });
    }
};
