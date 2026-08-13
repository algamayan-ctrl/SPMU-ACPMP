<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEvent extends Model
{
    protected $fillable = ['event_code', 'source_type', 'source_id', 'created_by_user_id', 'payload_snapshot_json', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload_snapshot_json' => 'array', 'occurred_at' => 'datetime'];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
