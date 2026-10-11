@extends('layouts.app')
@section('title', 'Request Access')
@section('content')
    <div class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-300/30 sm:p-10">
        <div class="flex items-center gap-3"><div class="grid size-10 place-items-center rounded-lg bg-blue-700 text-xs font-bold text-white">SNSU</div><div><p class="text-sm font-semibold text-blue-700">Facilities Management Office</p><h1 class="mt-0.5 text-2xl">Request an account</h1></div></div>
        <x-alert type="info" class="mt-6">Registration requires approval before application access is granted.</x-alert>
        <form method="POST" action="{{ route('register') }}" class="mt-7 space-y-6">
            @csrf
            <section class="form-section"><h2>Account Information</h2><p>Use your current institutional information.</p><div class="grid gap-5 sm:grid-cols-2"><label class="sm:col-span-2">Full name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name"></label><label class="sm:col-span-2">Email address <span class="text-rose-600">*</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label><label>Institutional category <span class="text-rose-600">*</span><select name="user_type" required><option value="">Select category</option>@foreach (['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'] as $value => $label)<option value="{{ $value }}" @selected(old('user_type') === $value)>{{ $label }}</option>@endforeach</select></label></div></section>
            <section class="form-section"><h2>Set a Password</h2><p>Use at least 12 characters and keep the password private.</p><div class="grid gap-5 sm:grid-cols-2"><label>Password <span class="text-rose-600">*</span><input id="register-password" type="password" name="password" required autocomplete="new-password"></label><label>Confirm password <span class="text-rose-600">*</span><input type="password" name="password_confirmation" required autocomplete="new-password"></label></div><button data-password-toggle="register-password" class="btn btn-ghost mt-3 !min-h-8 !px-2" type="button" aria-pressed="false">Show password</button></section>
            <div class="flex flex-wrap items-center gap-3"><button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Submit registration</button><a class="btn btn-secondary" href="{{ route('login') }}">Back to sign in</a></div>
        </form>
    </div>
@endsection
