<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSignature extends Model
{
    protected $fillable = ['user_id', 'stored_file_id', 'effective_from', 'effective_to', 'status'];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}
