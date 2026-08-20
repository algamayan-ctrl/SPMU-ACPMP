<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SanctionRule extends Model
{
    protected $fillable = [
        'offense_no',
        'sanction_code',
        'sanction_label',
        'duration_mode',
        'status',
        'effective_from',
        'effective_to',
        'configured_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'offense_no' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
