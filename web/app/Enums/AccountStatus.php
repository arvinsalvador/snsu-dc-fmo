<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case NeedsCorrection = 'NEEDS_CORRECTION';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';

    /** Rejected, suspended and deactivated accounts may not sign in or keep a session; pending and needs-correction accounts may only see their registration status. */
    public function blocksSignIn(): bool
    {
        return in_array($this, [self::Rejected, self::Suspended, self::Deactivated], true);
    }

    public function signInMessage(): string
    {
        return match ($this) {
            self::Rejected => 'This registration was not approved, so the account cannot sign in. Please contact the FMO administration.',
            self::Suspended => 'This account is suspended. Please contact the FMO administration.',
            self::Deactivated => 'This account is deactivated. Please contact the FMO administration.',
            default => 'This account cannot sign in.',
        };
    }
}
