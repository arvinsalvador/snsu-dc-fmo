@extends('layouts.app')
@section('title', 'Registration status')
@section('content')
<div class="max-w-2xl rounded-lg border bg-white p-6 shadow-sm">
    <h1 class="text-2xl font-semibold">Registration status</h1>
    <p class="mt-3 text-lg font-medium">{{ str_replace('_', ' ', $user->status->value) }}</p>
    @if ($user->status === \App\Enums\AccountStatus::Pending)<p class="mt-2">Your registration is awaiting approval.</p>@endif
    @if ($user->status === \App\Enums\AccountStatus::Approved)<p class="mt-2"><a class="text-blue-700 underline" href="{{ route('dashboard') }}">Continue to the FMO application</a></p>@endif
    @if ($user->status === \App\Enums\AccountStatus::NeedsCorrection)
        <p class="mt-2">Please update your information and resubmit your registration.</p>
        <form method="POST" action="{{ route('registration.resubmit') }}" class="mt-5 space-y-4">@csrf @method('PUT')
            <label class="block">Full name<input class="mt-1 w-full rounded border p-2" name="name" value="{{ old('name', $user->name) }}" required></label>
            <label class="block">Email<input class="mt-1 w-full rounded border p-2" type="email" name="email" value="{{ old('email', $user->email) }}" required></label>
            <label class="block">Institutional category<select class="mt-1 w-full rounded border p-2" name="user_type" required>
                @foreach (['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('user_type', $user->user_type) === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Resubmit registration</button>
        </form>
    @endif
</div>
<section class="mt-6 max-w-2xl"><h2 class="mb-3 text-lg font-semibold">Review history</h2>
    @forelse ($reviews as $review)
        <article class="mb-3 rounded border bg-white p-4"><p class="font-medium">{{ str_replace('_', ' ', ucfirst($review->action)) }} · {{ $review->created_at->format('M j, Y g:i A') }}</p>
            @if ($review->reason)<p class="mt-1">{{ $review->reason }}</p>@endif
        </article>
    @empty <p>No review decision yet.</p> @endforelse
</section>
@endsection
