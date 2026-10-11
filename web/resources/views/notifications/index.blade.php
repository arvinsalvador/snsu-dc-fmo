@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <x-page-header title="Notifications" description="Review workflow updates and items requiring your attention.">
        <x-slot:action><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-secondary" type="submit"><x-icon name="check-circle" />Mark all as read</button></form></x-slot:action>
    </x-page-header>
    <div class="mt-6 space-y-3">
        @forelse ($notifications as $item)
            <article class="rounded-xl border p-4 shadow-xs {{ $item->read_at ? 'border-slate-200 bg-white' : 'border-blue-300 bg-blue-50' }}">
                <div class="flex items-start gap-4"><span class="grid size-10 shrink-0 place-items-center rounded-full {{ $item->read_at ? 'bg-slate-100 text-slate-600' : 'bg-blue-100 text-blue-800' }}"><x-icon name="bell" /></span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-start justify-between gap-3"><div><div class="flex items-center gap-2"><h2 class="text-base">{{ $item->data['title'] ?? 'Notification' }}</h2>@unless ($item->read_at)<span class="status-badge status-open">Unread</span>@endunless</div><p class="mt-1 text-sm leading-6 text-slate-700">{{ $item->data['message'] ?? '' }}</p><p class="mt-2 text-xs text-slate-500">{{ $item->created_at?->timezone('Asia/Manila')->format('F j, Y · g:i A') }}</p></div>@unless ($item->read_at)<form method="POST" action="{{ route('notifications.read', $item->id) }}">@csrf<button class="btn btn-ghost !min-h-8 !px-2" type="submit">Mark read</button></form>@endunless</div>@if (! empty($item->data['action_url']))<a class="text-link mt-3" href="{{ $item->data['action_url'] }}">Open related Work Order<x-icon name="chevron-right" size="size-4" /></a>@endif</div></div>
            </article>
        @empty
            <x-empty-state title="No notifications yet" description="Workflow updates and assigned actions will appear here." icon="bell" />
        @endforelse
    </div><div class="mt-5">{{ $notifications->links() }}</div>
@endsection
