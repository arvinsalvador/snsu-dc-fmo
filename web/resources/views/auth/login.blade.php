@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="mx-auto max-w-md rounded-lg border bg-white p-6 shadow-sm">
    <h1 class="mb-2 text-2xl font-semibold">Sign in</h1>
    <p class="mb-5 text-sm text-slate-600">Access your registration status or the FMO application.</p>
    <form method="POST" action="{{ route('login') }}" class="space-y-4">@csrf
        <label class="block">Email<input class="mt-1 w-full rounded border p-2" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
        <label class="block">Password<input class="mt-1 w-full rounded border p-2" type="password" name="password" required autocomplete="current-password"></label>
        <button class="rounded bg-blue-700 px-4 py-2 text-white" type="submit">Sign in</button>
    </form>
    <p class="mt-5 text-sm">New applicant? <a class="text-blue-700 underline" href="{{ route('register') }}">Register here</a>.</p>
</div>
@endsection
