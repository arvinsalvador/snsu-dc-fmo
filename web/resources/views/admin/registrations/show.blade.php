@extends('layouts.app')
@section('title', 'Review registration')
@section('content')
<a class="text-sm text-blue-700 underline" href="{{ route('registrations.index') }}">← Registrations</a>
<h1 class="mt-3 text-2xl font-semibold">{{ $applicant->name }}</h1>
<div class="mt-4 rounded-lg border bg-white p-5"><dl class="grid gap-3 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Email</dt><dd>{{ $applicant->email }}</dd></div><div><dt class="text-sm text-slate-500">Institutional category</dt><dd>{{ ucfirst($applicant->user_type) }}</dd></div><div><dt class="text-sm text-slate-500">Registered</dt><dd>{{ $applicant->created_at->format('M j, Y g:i A') }}</dd></div><div><dt class="text-sm text-slate-500">Status</dt><dd>{{ str_replace('_', ' ', $applicant->status->value) }}</dd></div></dl></div>
@if ($applicant->status === \App\Enums\AccountStatus::Pending)
<div class="mt-6 grid gap-4 md:grid-cols-3">
    @can('users.approve_registration')<form method="POST" action="{{ route('registrations.review', $applicant) }}" class="rounded border bg-white p-4">@csrf<input type="hidden" name="action" value="approve"><h2 class="font-semibold">Approve registration</h2><button class="mt-3 rounded bg-green-700 px-4 py-2 text-white">Approve</button></form>@endcan
    @can('users.reject_registration')<form method="POST" action="{{ route('registrations.review', $applicant) }}" class="rounded border bg-white p-4">@csrf<input type="hidden" name="action" value="reject"><h2 class="font-semibold">Reject registration</h2><label class="mt-2 block text-sm">Reason<textarea class="mt-1 w-full rounded border p-2" name="reason" required></textarea></label><button class="mt-3 rounded bg-red-700 px-4 py-2 text-white">Reject</button></form>@endcan
    @can('users.request_registration_correction')<form method="POST" action="{{ route('registrations.review', $applicant) }}" class="rounded border bg-white p-4">@csrf<input type="hidden" name="action" value="request_correction"><h2 class="font-semibold">Request correction</h2><label class="mt-2 block text-sm">What needs correction?<textarea class="mt-1 w-full rounded border p-2" name="reason" required></textarea></label><button class="mt-3 rounded bg-blue-700 px-4 py-2 text-white">Send request</button></form>@endcan
</div>
@endif
<section class="mt-7"><h2 class="mb-3 text-lg font-semibold">Review history</h2>
@forelse ($reviews as $review)<div class="mb-2 rounded border bg-white p-4"><p class="font-medium">{{ str_replace('_', ' ', ucfirst($review->action)) }} · {{ $review->created_at->format('M j, Y g:i A') }}</p><p class="text-sm text-slate-600">By {{ $review->reviewer?->name ?? 'Applicant / system' }} · {{ $review->previous_status ?? 'New' }} → {{ $review->new_status }}</p>@if ($review->reason)<p class="mt-1">{{ $review->reason }}</p>@endif</div>@empty <p>No decisions yet.</p> @endforelse
</section>
@endsection
