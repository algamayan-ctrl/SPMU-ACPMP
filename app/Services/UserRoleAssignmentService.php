<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UserRoleAssignmentService
{
    public function synchronize(User $user, AccessClassification $classification, ?int $assignedByUserId = null): void
    {
        $requiredRoleIds = Role::query()
            ->whereIn('role_code', array_map(fn (UserRole $role) => $role->value, $classification->roles()))
            ->pluck('id');

        if ($requiredRoleIds->count() !== count($classification->roles())) {
            throw new RuntimeException('One or more required access roles are unavailable.');
        }

        DB::transaction(function () use ($user, $requiredRoleIds, $assignedByUserId): void {
            $now = now();

            DB::table('user_roles')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->whereNotIn('role_id', $requiredRoleIds)
                ->update(['revoked_at' => $now]);

            foreach ($requiredRoleIds as $roleId) {
                $activeAssignmentExists = DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->where('role_id', $roleId)
                    ->whereNull('revoked_at')
                    ->exists();

                if ($activeAssignmentExists) {
                    continue;
                }

                $assignedAt = $this->nextAssignmentTime($user->id, (int) $roleId, $now);
                DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'assigned_by_user_id' => $assignedByUserId,
                    'assigned_at' => $assignedAt,
                    'revoked_at' => null,
                ]);
            }
        }, 3);
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
}
