<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureSnapshot extends Model
{
    protected $fillable = ['user_signature_id', 'signer_user_id', 'snapshot_file_id', 'signer_name', 'signer_role', 'purpose_code', 'sha256', 'captured_at'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'snapshot_file_id');
    }
}
