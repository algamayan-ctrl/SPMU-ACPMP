<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaundryJob extends Model
{
    protected $fillable = [
        'custody_transaction_id',
        'generated_document_id',
        'latest_evidence_submission_id',
        'form_verified_by_user_id',
        'status',
        'worker_name',
        'worker_received_at',
        'worker_completed_at',
        'worker_remarks',
        'ready_at',
        'released_to_borrower_at',
        'form_verified_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'worker_received_at' => 'datetime',
            'worker_completed_at' => 'datetime',
            'ready_at' => 'datetime',
            'released_to_borrower_at' => 'datetime',
            'form_verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    public function latestEvidence(): BelongsTo
    {
        return $this->belongsTo(EvidenceSubmission::class, 'latest_evidence_submission_id');
    }

    public function formVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'form_verified_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LaundryJobLine::class);
    }
}
