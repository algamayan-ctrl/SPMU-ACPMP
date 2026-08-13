<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnLine extends Model
{
    protected $fillable = ['return_transaction_id', 'custody_line_id', 'quantity_received', 'condition_code', 'disposition_state', 'remarks'];

    protected function casts(): array
    {
        return ['quantity_received' => 'decimal:3'];
    }

    public function custodyLine(): BelongsTo
    {
        return $this->belongsTo(CustodyLine::class);
    }

    public function laundryRecord(): HasOne
    {
        return $this->hasOne(LaundryRecord::class);
    }
}
