<?php

namespace App\Models;

use App\Enums\ApprovalStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_version_id', 'approver_user_id', 'stage_code', 'sequence_no',
        'received_at', 'decision', 'decided_at', 'remarks', 'signature_snapshot_id',
        'temporary_delegation_id',
    ];

    protected function casts(): array
    {
        return ['stage_code' => ApprovalStage::class, 'received_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'request_version_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function signatureSnapshot(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'signature_snapshot_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(TemporaryDelegation::class, 'temporary_delegation_id');
    }
}
