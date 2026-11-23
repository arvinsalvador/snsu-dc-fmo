<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Support\PasswordPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebAuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function registerForm(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(AccountProfileService::normalize($request->only(['name', 'email'])));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'user_type' => ['required', Rule::in(['student', 'faculty', 'staff'])],
            'password' => PasswordPolicy::rules(),
        ], ['email.unique' => 'An account with this email address already exists.']);

        $user = new User($data);
        $user->status = AccountStatus::Pending;
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'An account with this email address already exists.'])->onlyInput('name', 'email', 'user_type');
        }
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('registration.status');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(AccountProfileService::normalize($request->only(['email'])));
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $provider = Auth::guard('web')->getProvider();
        $account = $provider->retrieveByCredentials(['email' => $credentials['email']]);
        if ($account && $provider->validateCredentials($account, $credentials) && $account->status?->blocksSignIn()) {
            return back()->withErrors(['email' => $account->signInBlockMessage()])->onlyInput('email');
        }

        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'The provided credentials are incorrect.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended($request->user()->status === AccountStatus::Approved
            ? route('dashboard') : route('registration.status'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
