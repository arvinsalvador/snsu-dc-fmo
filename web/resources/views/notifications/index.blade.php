@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="flex items-center justify-between gap-4">
    <h1 class="text-2xl font-semibold">Notifications</h1>
    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="rounded bg-slate-700 px-4 py-2 text-white">Mark all as read</button></form>
</div>
<div class="mt-5 space-y-3">
    @forelse ($notifications as $item)
        <article class="rounded border p-4 {{ $item->read_at ? 'bg-white' : 'border-blue-300 bg-blue-50' }}">
            <div class="flex items-start justify-between gap-3"><div>
                <strong>{{ $item->data['title'] ?? 'Notification' }}</strong>
                @unless ($item->read_at)<span class="ml-2 text-xs font-semibold text-blue-800">Unread</span>@endunless
                <p class="mt-1">{{ $item->data['message'] ?? '' }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ $item->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>
                @if (!empty($item->data['action_url']))<a class="text-blue-700 underline" href="{{ $item->data['action_url'] }}">Open related Work Order</a>@endif
            </div>
            @unless ($item->read_at)<form method="POST" action="{{ route('notifications.read', $item->id) }}">@csrf<button class="text-sm text-blue-800 underline">Mark read</button></form>@endunless
            </div>
        </article>
    @empty
        <p class="rounded border bg-white p-5">No notifications yet.</p>
    @endforelse
</div>
<div class="mt-5">{{ $notifications->links() }}</div>
@endsection
