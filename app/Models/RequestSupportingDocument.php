<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestSupportingDocument extends Model
{
    public const TYPE_SIGNED_REQUEST_LETTER = 'SIGNED_REQUEST_LETTER';
    public const TYPE_PTC = 'PTC';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'request_version_id',
        'stored_file_id',
        'uploaded_by_user_id',
        'document_type',
        'status',
        'uploaded_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'request_version_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function label(): string
    {
        return match ($this->document_type) {
            self::TYPE_SIGNED_REQUEST_LETTER => 'Signed Borrowing Request Letter',
            self::TYPE_PTC => 'Permission to Conduct Letter (PTC)',
            default => $this->document_type,
        };
    }
}
