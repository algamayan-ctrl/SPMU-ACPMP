<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'version_no',
        'purpose_event',
        'location',
        'needed_from',
        'return_due_at',
        'represents_student_activity',
        'student_organization',
        'represented_program_department',
        'represented_year_level',
        'event_details',
        'off_campus',
        'remarks',
        'signed_at',
        'submitted_at',
        'borrower_signature_snapshot_id',
        'created_by_user_id',
        'accuracy_certified',
    ];

    protected function casts(): array
    {
        return [
            'needed_from' => 'datetime',
            'return_due_at' => 'datetime',
            'off_campus' => 'boolean',
            'represents_student_activity' => 'boolean',
            'signed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'accuracy_certified' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BorrowingRequest::class, 'request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class);
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function supportingDocuments(): HasMany
    {
        return $this->hasMany(RequestSupportingDocument::class);
    }

    /**
     * Legacy relation retained temporarily for backward compatibility.
     * New borrowing-request submission no longer relies on an electronic
     * borrower signature snapshot.
     */
    public function borrowerSignature(): BelongsTo
    {
        return $this->belongsTo(SignatureSnapshot::class, 'borrower_signature_snapshot_id');
    }
}
