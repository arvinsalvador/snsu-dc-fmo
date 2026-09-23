@extends('layouts.app')
@section('title', 'Register')
@section('content')
<div class="mx-auto max-w-lg rounded-lg border bg-white p-6 shadow-sm">
    <h1 class="mb-2 text-2xl font-semibold">Request an account</h1>
    <p class="mb-5 text-sm text-slate-600">An authorized reviewer must approve your registration before you can use the FMO system.</p>
    <form method="POST" action="{{ route('register') }}" class="space-y-4">@csrf
        <label class="block">Full name<input class="mt-1 w-full rounded border p-2" name="name" value="{{ old('name') }}" required maxlength="255"></label>
        <label class="block">Email<input class="mt-1 w-full rounded border p-2" type="email" name="email" value="{{ old('email') }}" required></label>
        <label class="block">Institutional category<select class="mt-1 w-full rounded border p-2" name="user_type" required>
            <option value="">Select one</option>
            @foreach (['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'] as $value => $label)
                <option value="{{ $value }}" @selected(old('user_type') === $value)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label class="block">Password (12 characters minimum)<input class="mt-1 w-full rounded border p-2" type="password" name="password" required autocomplete="new-password"></label>
        <label class="block">Confirm password<input class="mt-1 w-full rounded border p-2" type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="rounded bg-blue-700 px-4 py-2 text-white" type="submit">Submit registration</button>
    </form>
</div>
@endsection
