<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPeriod extends Model
{
    protected $fillable = [
        'academic_year',
        'term_code',
        'term_name',
        'start_date',
        'end_date',
        'status',
        'configured_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(BorrowerViolation::class);
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }
}
