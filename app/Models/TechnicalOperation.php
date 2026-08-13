<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TechnicalOperation extends Model
{
    protected $fillable = ['performed_by_user_id', 'operation_type', 'status', 'reference', 'details', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
