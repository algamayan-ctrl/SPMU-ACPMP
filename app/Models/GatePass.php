<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatePass extends Model
{
    protected $fillable = [
        'custody_transaction_id',
        'custody_line_id',
        'pass_document_id',
        'accomplished_file_id',
        'uploaded_by_user_id',
        'uploaded_at',
        'verified_by_user_id',

        /* Historical digital-signature fields retained for old records. */
        'prepared_verified_by_user_id',
        'prepared_verifier_signature_snapshot_id',
        'prepared_verified_at',
        'approved_by_user_id',
        'approver_signature_snapshot_id',
        'temporary_delegation_id',
        'approved_at',

        'bearer_name',
        'destination',
        'purpose',
        'guard_name',
        'guard_signed_at',
        'status',
        'verified_at',
        'verification_remarks',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'prepared_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'guard_signed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function passDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'pass_document_id');
    }

    public function accomplishedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'accomplished_file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /* Historical relations. */
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
