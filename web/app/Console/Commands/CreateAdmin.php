<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Interactively create or update the initial system administrator';

    public function handle(): int
    {
        $name = $this->ask('Full name');
        $email = $this->ask('Email');
        $userType = $this->choice('Institutional user type', ['student', 'faculty', 'staff'], 2);
        $password = $this->secret('Password (at least 12 characters)');
        $confirmation = $this->secret('Confirm password');

        $validation = Validator::make(compact('name', 'email', 'userType', 'password', 'confirmation'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'userType' => ['required', Rule::in(['student', 'faculty', 'staff'])],
            'password' => ['required', 'string', 'min:12', 'same:confirmation'],
        ]);
        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();
        if ($existing && ! $this->confirm('This account exists. Update it and grant System Administrator?', false)) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $userType, $password, $existing): void {
            $user = $existing ?? new User;
            $previous = $existing?->status?->value;
            $user->fill(['name' => $name, 'email' => $email, 'user_type' => $userType, 'password' => $password]);
            $user->status = AccountStatus::Approved;
            $user->approved_at ??= now();
            $user->save();
            $user->assignRole('System Administrator');
            $user->reviews()->create([
                'action' => 'status_changed',
                'previous_status' => $previous,
                'new_status' => AccountStatus::Approved->value,
                'reason' => 'Interactive administrator bootstrap',
            ]);
        });

        $this->info('System Administrator is ready.');

        return self::SUCCESS;
    }
}
