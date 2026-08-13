<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceSubmission extends Model
{
    protected $fillable = ['generated_document_id', 'stored_file_id', 'borrower_user_id', 'uploaded_by_user_id', 'verified_by_user_id', 'upload_mode', 'fallback_reason', 'borrower_notified_at', 'submitted_at', 'verification_status', 'verified_at', 'rejection_reason'];

    protected function casts(): array
    {
        return ['borrower_notified_at' => 'datetime', 'submitted_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
