<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryDelegation extends Model
{
    protected $fillable = [
        'office_role', 'absent_head_user_id', 'delegate_user_id', 'recorded_by_user_id',
        'authority_reference', 'reason', 'effective_from', 'effective_to', 'status',
        'revoked_at', 'revoked_by_user_id', 'revocation_reason',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function absentHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'absent_head_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isEffective(): bool
    {
        return $this->status === 'ACTIVE'
            && $this->revoked_at === null
            && $this->effective_from->lte(now())
            && $this->effective_to->gte(now());
    }
}
