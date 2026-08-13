<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'DRAFT';
    case Signed = 'SIGNED';
    case Submitted = 'SUBMITTED';
    case UnderSpmu = 'UNDER_SPMU';
    case UnderGsu = 'UNDER_GSU';
    case UnderVpaf = 'UNDER_VPAF';
    case ReturnedForRevision = 'RETURNED_FOR_REVISION';
    case Rejected = 'REJECTED';
    case FinalApprovedAwaitingDownload = 'FINAL_APPROVED_AWAITING_DOWNLOAD';
    case ApprovedReadyForRelease = 'APPROVED_READY_FOR_RELEASE';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Signed => 'Signed',
            self::Submitted => 'Submitted',
            self::UnderSpmu => 'Under SPMU Review',
            self::UnderGsu => 'Under GSU Review',
            self::UnderVpaf => 'Under VPAF Review',
            self::ReturnedForRevision => 'Returned for Revision',
            self::Rejected => 'Rejected',
            self::FinalApprovedAwaitingDownload => 'Approved - Download Required',
            self::ApprovedReadyForRelease => 'Approved - Ready for Release',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Rejected, self::Cancelled, self::Expired], true);
    }
}
