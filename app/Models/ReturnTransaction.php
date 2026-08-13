<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnTransaction extends Model
{
    protected $fillable = ['return_no', 'custody_transaction_id', 'received_by_user_id', 'return_type', 'received_at', 'status', 'remarks'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function custody(): BelongsTo
    {
        return $this->belongsTo(CustodyTransaction::class, 'custody_transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class);
    }
}
