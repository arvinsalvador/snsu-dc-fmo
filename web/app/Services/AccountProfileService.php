<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service and administrator changes to a user's personal profile and password.
 * Only the fields named here can change; roles, permissions, and account status never pass through.
 */
class AccountProfileService
{
    public const AVATAR_MAX_KB = 3072;

    public function __construct(private readonly AvatarService $avatars, private readonly AccountAuditService $audit) {}

    /** Name and email are trimmed and normalised before validation so duplicates cannot differ by case or spacing. */
    public static function normalize(array $input): array
    {
        if (array_key_exists('name', $input) && is_string($input['name'])) {
            $input['name'] = trim(preg_replace('/\s+/u', ' ', $input['name']) ?? '');
        }
        if (array_key_exists('email', $input) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        return $input;
    }

    /**
     * Rules for personal details. People edit only their own name and contact number; the email
     * address is changed solely by an administrator ($withEmail), so it is never read from a self-service request.
     *
     * @return array<string, mixed>
     */
    public function personalRules(User $user, bool $withEmail = false, bool $withContact = true): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            ...($withEmail ? ['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)]] : []),
            ...($withContact ? ['contact_number' => ['nullable', 'string', 'max:50']] : []),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'An account with this email address already exists.'];
    }

    /** @return array<string, mixed> */
    public function photoRules(): array
    {
        return ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::AVATAR_MAX_KB]];
    }

    /** @param array{name: string, email?: string, contact_number?: ?string} $data */
    public function updatePersonal(User $user, User $actor, array $data): void
    {
        try {
            DB::transaction(function () use ($user, $actor, $data): void {
                $before = ['name' => $user->name, 'email' => $user->email];
                $user->name = $data['name'];
                if (array_key_exists('email', $data)) {
                    $user->email = $data['email'];
                }
                $user->save();

                if (array_key_exists('contact_number', $data)) {
                    $personnel = $user->personnelProfile;
                    if ($personnel) {
                        $personnel->update(['contact_number' => $data['contact_number']]);
                    } else {
                        $user->requesterProfile()->updateOrCreate([], ['contact_number' => $data['contact_number']]);
                    }
                }
                if ($before['email'] !== $user->email) {
                    $this->audit->record($user, $actor, 'email_changed', ['from' => $before['email'], 'to' => $user->email]);
                }
                if ($before['name'] !== $user->name) {
                    $this->audit->record($user, $actor, 'profile_updated', ['name_from' => $before['name'], 'name_to' => $user->name]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => $this->messages()['email.unique']]);
        }
    }

    public function replacePhoto(User $user, User $actor, UploadedFile $photo): void
    {
        $this->avatars->replace($user, $photo);
        $this->audit->record($user, $actor, 'photo_changed');
    }

    public function removePhoto(User $user, User $actor): void
    {
        if ($user->hasAvatar()) {
            $this->avatars->remove($user);
            $this->audit->record($user, $actor, 'photo_removed');
        }
    }

    /** Change the user's own password. API tokens other than the current one are revoked. */
    public function changeOwnPassword(User $user, string $password, ?int $keepTokenId = null): void
    {
        DB::transaction(function () use ($user, $password, $keepTokenId): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->when($keepTokenId, fn ($tokens) => $tokens->whereKeyNot($keepTokenId))->delete();
            $this->audit->record($user, $user, 'password_changed');
        });
    }

    /** Administrator reset. The target is signed out everywhere; the new password is never stored in the audit trail. */
    public function resetPassword(User $target, User $actor, string $password): void
    {
        DB::transaction(function () use ($target, $actor, $password): void {
            $target->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $target->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
            }
            $this->audit->record($target, $actor, 'password_reset', ['by' => $actor->name]);
        });
    }
}
