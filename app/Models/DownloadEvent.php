<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadEvent extends Model
{
    protected $fillable = ['generated_document_id', 'downloaded_by_user_id', 'downloaded_at', 'integrity_hash', 'origin_ip', 'user_agent'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }
}
