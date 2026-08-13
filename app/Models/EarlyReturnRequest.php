<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EarlyReturnRequest extends Model
{
    protected $fillable = ['early_return_no', 'custody_transaction_id', 'requested_by_user_id', 'proposed_return_at', 'reason', 'status', 'requested_at', 'completed_at'];

    protected function casts(): array
    {
        return ['proposed_return_at' => 'datetime', 'requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EarlyReturnRequestLine::class);
    }
}
