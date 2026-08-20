<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * GSU and VPAF remain names on the physical Borrowing Request Letter
         * only. Historical rows stay in the database for audit/FK integrity,
         * but they are not active application accounts, roles, or units.
         */
        if (Schema::hasTable('users')) {
            DB::table('users')
                ->whereIn('access_classification', ['GSU_HEAD', 'VPAF_HEAD'])
                ->update(['account_status' => 'INACTIVE']);

            // Remove obsolete "+ Borrower" wording from active staff records.
            $designationMap = [
                'ICTU Maintainer + Borrower' => 'ICTU Maintainer',
                'SPMU Action Officer + Borrower' => 'SPMU Action Officer',
                'SPMU Officer + Borrower' => 'SPMU Action Officer',
            ];

            foreach ($designationMap as $old => $new) {
                DB::table('users')
                    ->where('designation', $old)
                    ->update(['designation' => $new]);
            }
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')
                ->whereIn('role_code', ['GSU', 'VPAF'])
                ->update(['active' => false]);
        }

        if (Schema::hasTable('organizational_units')) {
            DB::table('organizational_units')
                ->whereIn('unit_code', ['GSU', 'VPAF'])
                ->update(['active' => false]);
        }

        if (
            Schema::hasTable('user_roles')
            && Schema::hasTable('roles')
        ) {
            $legacyRoleIds = DB::table('roles')
                ->whereIn('role_code', ['GSU', 'VPAF'])
                ->pluck('id');

            if ($legacyRoleIds->isNotEmpty()) {
                DB::table('user_roles')
                    ->whereIn('role_id', $legacyRoleIds)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: retired portal authority must not be
        // re-enabled automatically by a rollback.
    }
};
