<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustodyTransaction extends Model
{
    protected $fillable = ['custody_no', 'request_id', 'request_version_id', 'borrower_user_id', 'released_by_user_id', 'prepared_by_user_id', 'borrower_ack_signature_snapshot_id', 'laundry_borrower_signature_snapshot_id', 'laundry_approved_by_user_id', 'laundry_approver_signature_snapshot_id', 'laundry_temporary_delegation_id', 'laundry_approved_at', 'status', 'scheduled_release_at', 'released_at', 'prepared_at', 'due_at', 'acknowledged_at', 'closed_at'];

    protected function casts(): array
    {
        return ['scheduled_release_at' => 'datetime', 'released_at' => 'datetime', 'prepared_at' => 'datetime', 'due_at' => 'datetime', 'acknowledged_at' => 'datetime', 'laundry_approved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BorrowingRequest::class, 'request_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function acknowledgementSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'borrower_ack_signature_snapshot_id');
    }

    public function laundryBorrowerSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'laundry_borrower_signature_snapshot_id');
    }

    public function laundryApproverSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'laundry_approver_signature_snapshot_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustodyLine::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnTransaction::class);
    }

    public function gatePass(): HasOne
    {
        return $this->hasOne(GatePass::class);
    }

    public function overdueCase(): HasOne
    {
        return $this->hasOne(OverdueCase::class);
    }

    public function earlyReturnRequests(): HasMany
    {
        return $this->hasMany(EarlyReturnRequest::class);
    }
}
