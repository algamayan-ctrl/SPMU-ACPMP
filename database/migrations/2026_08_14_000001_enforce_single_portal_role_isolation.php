<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $classificationRoles = [
            'BORROWER_ONLY' => 'BORROWER',
            'SPMU_HEAD' => 'SPMU',
            'SPMU_OFFICER' => 'SPMU',
            'GSU_HEAD' => 'GSU',
            'VPAF_HEAD' => 'VPAF',
            'ICTU_MAINTAINER' => 'ICTU',
        ];

        $roleIds = DB::table('roles')
            ->whereIn('role_code', array_values(array_unique($classificationRoles)))
            ->pluck('id', 'role_code');

        if (DB::table('users')->whereIn('access_classification', array_keys($classificationRoles))->exists()
            && $roleIds->count() !== count(array_unique($classificationRoles))) {
            throw new RuntimeException('Single-portal role synchronization requires all standard roles to exist.');
        }

        DB::transaction(function () use ($classificationRoles, $roleIds): void {
            DB::table('users')
                ->whereIn('access_classification', array_keys($classificationRoles))
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($classificationRoles, $roleIds): void {
                    foreach ($users as $user) {
                        $requiredRoleId = (int) $roleIds[$classificationRoles[$user->access_classification]];
                        $now = now();

                        DB::table('user_roles')
                            ->where('user_id', $user->id)
                            ->whereNull('revoked_at')
                            ->where('role_id', '!=', $requiredRoleId)
                            ->update(['revoked_at' => $now]);

                        $requiredRoleIsActive = DB::table('user_roles')
                            ->where('user_id', $user->id)
                            ->where('role_id', $requiredRoleId)
                            ->whereNull('revoked_at')
                            ->exists();

                        if (! $requiredRoleIsActive) {
                            DB::table('user_roles')->insert([
                                'user_id' => $user->id,
                                'role_id' => $requiredRoleId,
                                'assigned_by_user_id' => null,
                                'assigned_at' => $this->nextAssignmentTime((int) $user->id, $requiredRoleId, $now),
                                'revoked_at' => null,
                            ]);
                        }
                    }
                });
        }, 3);
    }

    public function down(): void
    {
        // Intentionally no-op: restoring obsolete Borrower authority would weaken access control.
        // Revoked assignments remain available as historical records.
    }

    private function nextAssignmentTime(int $userId, int $roleId, Carbon $candidate): Carbon
    {
        $latest = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->max('assigned_at');

        if ($latest && Carbon::parse($latest)->gte($candidate)) {
            return Carbon::parse($latest)->addSecond();
        }

        return $candidate;
    }
};
