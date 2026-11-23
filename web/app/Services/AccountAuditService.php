<?php

namespace App\Services;

use App\Models\AccountAuditEvent;
use App\Models\User;

/** Records account and security changes. Passwords and hashes are never written. */
class AccountAuditService
{
    /** @param array<string, mixed>|null $meta */
    public function record(User $subject, ?User $actor, string $event, ?array $meta = null): AccountAuditEvent
    {
        return AccountAuditEvent::create(['user_id' => $subject->id, 'actor_id' => $actor?->id, 'event' => $event, 'meta' => $meta]);
    }
}
