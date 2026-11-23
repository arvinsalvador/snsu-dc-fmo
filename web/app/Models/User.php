<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Support\Initials;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'user_type'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    public function reviews(): HasMany
    {
        return $this->hasMany(RegistrationReview::class);
    }

    public function requesterProfile(): HasOne
    {
        return $this->hasOne(RequesterProfile::class);
    }

    public function personnelProfile(): HasOne
    {
        return $this->hasOne(FmoPersonnel::class);
    }

    public function fmoContactThreads(): HasMany
    {
        return $this->hasMany(FmoContactThread::class, 'requester_user_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AccountAuditEvent::class)->latest('created_at');
    }

    public function initials(): string
    {
        return Initials::from($this->name);
    }

    public function hasAvatar(): bool
    {
        return filled($this->avatar_path);
    }

    /** URL of the profile picture, versioned so a replaced photo is never served stale. Reads only this row. */
    public function avatarUrl(bool $api = false): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return route($api ? 'api.avatars.show' : 'avatars.show', ['user' => $this->id, 'v' => $this->avatar_updated_at?->timestamp ?? 0]);
    }

    /** Why this account may not sign in. A rejected registration includes the reviewer's reason so the applicant still learns the decision. */
    public function signInBlockMessage(): string
    {
        $message = $this->status->signInMessage();
        if ($this->status === AccountStatus::Rejected) {
            $reason = $this->reviews()->where('action', 'rejected')->latest('id')->value('reason');
            if (filled($reason)) {
                $message .= ' Reason: '.$reason;
            }
        }

        return $message;
    }

    public function isApproved(): bool
    {
        return $this->status === AccountStatus::Approved;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'approved_at' => 'datetime',
            'avatar_updated_at' => 'datetime',
        ];
    }
}
