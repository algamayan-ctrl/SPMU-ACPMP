@props(['status', 'label' => null])

@php
    $value = $status instanceof BackedEnum ? $status->value : (string) $status;
    $key = strtoupper($value);
    $labels = [
        'DRAFT' => 'Draft',
        'UNDER_SPMU' => 'Under SPMU Review',
        'UNDER_GSU' => 'Under GSU Review',
        'UNDER_VPAF' => 'Under VPAF Review',
        'PENDING' => 'Pending',
        'RECEIVED' => 'Pending Review',
        'RETURNED_FOR_REVISION' => 'Returned for Revision',
        'FINAL_APPROVED_AWAITING_DOWNLOAD' => 'Approved Letter Ready',
        'APPROVED_READY_FOR_RELEASE' => 'Ready for Release',
        'PREPARING_RELEASE' => 'Preparing for Release',
        'PARTIALLY_RETURNED' => 'Partially Returned',
        'OBLIGATION_OPEN' => 'Outstanding Obligation',
        'EARLY_RETURN' => 'Early Return Requested',
        'INCIDENT_OPEN' => 'Accountability Review',
        'RECEIPT_SUBMITTED' => 'Receipt Submitted',
        'PENDING_VERIFICATION' => 'Pending Verification',
        'SUBMITTED_PENDING_ORIGINAL' => 'Pending Original Receipt',
        'RETURNED_PENDING_SETTLEMENT' => 'Pending Settlement',
        'SERVICEABLE' => 'Serviceable',
        'DAMAGED_MAINTENANCE' => 'Damaged / Maintenance',
        'CONDEMNED' => 'Condemned',
        'ALLOCATED' => 'Fully Allocated',
        'BORROWED' => 'On Custody',
        'LAUNDRY' => 'In Laundry',
        'PREPARED' => 'Prepared',
        'RELEASED_PENDING_RETURN' => 'Issued / Awaiting Return',
        'IN_LAUNDRY' => 'In Laundry',
        'INCIDENT_PENDING' => 'Accountability Review',
        'BILLING_PENDING' => 'Billing Pending',
        'SUBMITTED' => 'Submitted',
        'ISSUED' => 'Pending Payment',
        'SETTLED' => 'Paid / Verified',
        'LIFTED' => 'Lifted',
        'BORROWER_ONLY' => 'Borrower',
        'SPMU_HEAD' => 'SPMU Head',
        'SPMU_OFFICER' => 'SPMU Officer',
        'GSU_HEAD' => 'GSU Head',
        'VPAF_HEAD' => 'VPAF Head',
        'ICTU_MAINTAINER' => 'ICTU Maintainer',
    ];
    $display = $label ?: ($labels[$key] ?? str($value)->replace('_', ' ')->lower()->title());
    $tone = match (true) {
        str_contains($key, 'OVERDUE') || in_array($key, ['REJECTED', 'EXPIRED', 'FAILED', 'UNAVAILABLE', 'CRITICAL', 'LOST', 'DESTROYED', 'STOLEN', 'CONDEMNED'], true) => 'danger',
        str_contains($key, 'APPROVED') || str_contains($key, 'COMPLETED') || str_contains($key, 'VERIFIED') || in_array($key, ['ACTIVE', 'AVAILABLE', 'READY_FOR_RELEASE', 'RELEASED', 'RETURNED', 'SETTLED', 'EFFECTIVE', 'FINAL', 'CLOSED'], true) => 'success',
        str_contains($key, 'RETURNED_FOR_REVISION') || str_contains($key, 'DUE_SOON') || str_contains($key, 'PENDING') || str_contains($key, 'AWAITING') || str_contains($key, 'LOW_STOCK') || str_contains($key, 'OBLIGATION') || str_contains($key, 'DAMAGED') || $key === 'MISSING' => 'warning',
        str_contains($key, 'UNDER_') || str_contains($key, 'PREPARING') || in_array($key, ['INFORMATIONAL', 'INFO', 'SUBMITTED', 'UNREAD', 'RECEIVED', 'ALLOCATED', 'BORROWED', 'RELEASED_PENDING_RETURN'], true) => 'info',
        in_array($key, ['INACTIVE', 'NOT_APPLICABLE', 'NOT_CONFIGURED', 'CANCELLED', 'VOID', 'WAIVED', 'SUPERSEDED', 'INVALIDATED'], true) => 'neutral',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-'.$tone])->merge(['data-status-tone' => $tone]) }}>{{ $display }}</span>
