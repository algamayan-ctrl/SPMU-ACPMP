<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestSupportingDocument extends Model
{
    public const TYPE_REQUEST_LETTER = 'SIGNED_BR_LETTER';
    public const TYPE_PERMISSION_TO_CONDUCT = 'PTC_LETTER';

    public const STATUS_PENDING = 'PENDING_VERIFICATION';
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_RETURNED_FOR_REVISION = 'RETURNED_FOR_REVISION';
    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'request_id',
        'request_version_id',
        'document_type',
        'version_no',
        'stored_file_id',
        'uploaded_by_user_id',
        'uploaded_at',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'verification_remarks',
        'is_current',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_current' => 'boolean',
            'superseded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BorrowingRequest::class, 'request_id');
    }

    public function requestVersion(): BelongsTo
    {
        return $this->belongsTo(RequestVersion::class, 'request_version_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function isRequestLetter(): bool
    {
        return $this->document_type === self::TYPE_REQUEST_LETTER;
    }

    public function isPermissionToConduct(): bool
    {
        return $this->document_type === self::TYPE_PERMISSION_TO_CONDUCT;
    }
}
