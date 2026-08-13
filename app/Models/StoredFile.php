<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoredFile extends Model
{
    protected $fillable = ['uploaded_by_user_id', 'disk', 'storage_path', 'original_name', 'mime_type', 'byte_size', 'sha256', 'classification'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
