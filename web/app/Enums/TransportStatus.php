<?php

namespace App\Enums;

enum TransportStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Assigned = 'ASSIGNED';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->value();
    }
}
