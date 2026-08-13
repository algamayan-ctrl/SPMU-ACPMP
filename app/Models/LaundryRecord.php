<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaundryRecord extends Model
{
    protected $fillable = ['return_line_id', 'form_document_id', 'verified_by_user_id', 'worker_name', 'worker_received_at', 'worker_completed_at', 'cleaned_quantity', 'damaged_quantity', 'status', 'verified_at'];

    protected function casts(): array
    {
        return ['worker_received_at' => 'datetime', 'worker_completed_at' => 'datetime', 'cleaned_quantity' => 'decimal:3', 'damaged_quantity' => 'decimal:3', 'verified_at' => 'datetime'];
    }

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(ReturnLine::class);
    }
}
