<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyRoleIds = DB::table('roles')
            ->whereIn('role_code', ['GSU', 'VPAF'])
            ->pluck('id');

        if ($legacyRoleIds->isNotEmpty()) {
            DB::table('user_roles')
                ->whereIn('role_id', $legacyRoleIds)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            DB::table('roles')
                ->whereIn('id', $legacyRoleIds)
                ->update(['active' => false]);
        }

        DB::table('users')
            ->whereIn('access_classification', ['GSU_HEAD', 'VPAF_HEAD'])
            ->update([
                'account_status' => 'INACTIVE',
                'updated_at' => now(),
            ]);

        /*
         * GSU and VPAF remain institutional signatories on the physical
         * Borrowing Request Letter only. They must not appear as active
         * account-assignment units in ICTU administration.
         */
        DB::table('organizational_units')
            ->whereIn('unit_code', ['GSU', 'VPAF'])
            ->update([
                'active' => false,
                'updated_at' => now(),
            ]);

        /*
         * Any request left in the retired electronic approval stages is
         * returned to the borrower so it can be resubmitted under the
         * current SPMU-only verification workflow without rewriting
         * historical approval-step records.
         */
        DB::table('borrowing_requests')
            ->whereIn('status', ['UNDER_GSU', 'UNDER_VPAF'])
            ->update([
                'status' => 'RETURNED_FOR_REVISION',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally not reactivating retired approval roles automatically.
        // Any restoration must be an explicit institutional decision.
    }
};
