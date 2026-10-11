@extends('layouts.app')
@section('title', 'Registration Status')
@section('content')
    <div class="mx-auto max-w-3xl">
        <section class="section-card">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="detail-kicker">Account Access</p><h1 class="mt-2">Registration Status</h1><p class="mt-2 text-sm text-slate-600">Follow the current review decision and any required next step.</p></div><x-status-badge :status="$user->status" /></div>
            @if ($user->status === \App\Enums\AccountStatus::Pending)<x-alert type="info" class="mt-6">Your registration is awaiting approval from an authorized reviewer. No further action is needed right now.</x-alert>@endif
            @if ($user->status === \App\Enums\AccountStatus::Approved)<x-alert type="success" class="mt-6">Your registration is approved. <a class="font-semibold underline" href="{{ route('dashboard') }}">Continue to the FMO application</a>.</x-alert>@endif
            @if ($user->status === \App\Enums\AccountStatus::NeedsCorrection)
                <x-alert type="warning" class="mt-6">Update the requested information, then resubmit your registration for review.</x-alert>
                <form method="POST" action="{{ route('registration.resubmit') }}" class="mt-6 space-y-5">@csrf @method('PUT')<label class="block">Full name <span class="text-rose-600">*</span><input name="name" value="{{ old('name', $user->name) }}" required></label><label class="block">Email address <span class="text-rose-600">*</span><input type="email" name="email" value="{{ old('email', $user->email) }}" required></label><label class="block">Institutional category <span class="text-rose-600">*</span><select name="user_type" required>@foreach (['student' => 'Student', 'faculty' => 'Faculty', 'staff' => 'Staff'] as $value => $label)<option value="{{ $value }}" @selected(old('user_type', $user->user_type) === $value)>{{ $label }}</option>@endforeach</select></label><button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Resubmit registration</button></form>
            @endif
            @if (in_array($user->status->value, ['REJECTED', 'SUSPENDED', 'DEACTIVATED'], true))<x-alert type="danger" class="mt-6">This account cannot currently access the application. Review the decision history below or contact the Facilities Management Office for assistance.</x-alert>@endif
        </section>
        <section class="mt-7"><h2 class="text-lg">Review History</h2><div class="section-card mt-4">@forelse ($reviews as $review)<article class="timeline-item"><p class="font-semibold text-slate-900">{{ str($review->action)->replace('_', ' ')->title() }}</p><p class="mt-1 text-sm text-slate-500">{{ $review->created_at->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</p>@if ($review->reason)<p class="mt-2 text-sm text-slate-700">{{ $review->reason }}</p>@endif</article>@empty<x-empty-state title="No review decision yet" description="Updates will appear here after an authorized reviewer acts." icon="clock" />@endforelse</div></section>
    </div>
@endsection
