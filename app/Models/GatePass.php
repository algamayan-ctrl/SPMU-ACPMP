<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePass extends Model
{
    protected $fillable = ['custody_transaction_id', 'custody_line_id', 'pass_document_id', 'verified_by_user_id', 'prepared_verified_by_user_id', 'prepared_verifier_signature_snapshot_id', 'prepared_verified_at', 'approved_by_user_id', 'approver_signature_snapshot_id', 'temporary_delegation_id', 'approved_at', 'bearer_name', 'destination', 'purpose', 'guard_name', 'guard_signed_at', 'status', 'verified_at'];

    protected function casts(): array
    {
        return ['prepared_verified_at' => 'datetime', 'approved_at' => 'datetime', 'guard_signed_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function preparedVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_verified_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(TemporaryDelegation::class, 'temporary_delegation_id');
    }

    public function preparedVerifierSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'prepared_verifier_signature_snapshot_id');
    }

    public function approverSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'approver_signature_snapshot_id');
    }
}
