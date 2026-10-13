@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <x-page-header title="Notifications" description="Review Work Order activity, assignments, and actions that need your attention.">
        @if ($unreadCount > 0)
            <x-slot:action>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit"><x-icon name="check-circle" />Mark all as read</button>
                </form>
            </x-slot:action>
        @endif
    </x-page-header>

    <div class="mt-6 border-b border-slate-200" aria-label="Notification views">
        <nav class="-mb-px flex gap-6" aria-label="Tabs">
            <a class="border-b-2 px-1 pb-3 text-sm font-semibold {{ $tab === 'all' ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}" href="{{ route('notifications.index', array_filter(['type' => $type, 'date' => $date, 'search' => $search])) }}" @if ($tab === 'all') aria-current="page" @endif>All</a>
            <a class="flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-semibold {{ $tab === 'unread' ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800' }}" href="{{ route('notifications.index', array_filter(['tab' => 'unread', 'type' => $type, 'date' => $date, 'search' => $search])) }}" @if ($tab === 'unread') aria-current="page" @endif>
                Unread
                <span class="inline-grid min-w-6 place-items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-xs text-blue-800">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
            </a>
        </nav>
    </div>

    <form class="filter-bar mt-5" method="GET" action="{{ route('notifications.index') }}">
        @if ($tab === 'unread')<input type="hidden" name="tab" value="unread">@endif
        <label class="min-w-52 flex-1">Search<input name="search" value="{{ $search }}" maxlength="100" placeholder="Search Work Order, requester or notification..."></label>
        <label>
            Type
            <select name="type">
                <option value="">All types</option>
                @foreach ($typeOptions as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <label>
            Date
            <select name="date">
                <option value="">Any time</option>
                <option value="7" @selected($date === '7')>Last 7 days</option>
                <option value="30" @selected($date === '30')>Last 30 days</option>
                <option value="90" @selected($date === '90')>Last 90 days</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply filters</button>
        <a class="btn btn-ghost" href="{{ route('notifications.index', $tab === 'unread' ? ['tab' => 'unread'] : []) }}">Reset</a>
    </form>

    @if ($notifications->isNotEmpty())
        <div class="mt-7 space-y-8">
            @foreach ($groupedNotifications as $group => $items)
                <section aria-labelledby="notification-group-{{ \Illuminate\Support\Str::slug($group) }}">
                    <div class="mb-3 flex items-center gap-3"><h2 id="notification-group-{{ \Illuminate\Support\Str::slug($group) }}" class="text-sm font-semibold text-slate-700">{{ $group }}</h2><span class="h-px flex-1 bg-slate-200"></span></div>
                    <div class="space-y-3">
                        @foreach ($items as $item)
                            @php($notification = $item['notification'])
                            @php($notice = $item['presentation'])
                            <article class="relative overflow-hidden rounded-xl border p-4 shadow-xs sm:p-5 {{ $notice['is_unread'] ? 'border-blue-200 bg-blue-50/60' : 'border-slate-200 bg-white' }}">
                                @if ($notice['is_unread'])<span class="absolute inset-y-0 left-0 w-1 bg-blue-600" aria-hidden="true"></span>@endif
                                <div class="flex items-start gap-3 sm:gap-4">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full sm:size-11 {{ $notice['icon_classes'] }}"><x-icon :name="$notice['icon']" /></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h3 class="font-semibold text-slate-900">{{ $notice['title'] }}</h3>
                                                    <span class="status-badge status-neutral">{{ $notice['event_label'] }}</span>
                                                    @if ($notice['is_unread'])
                                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-800"><span class="size-2 rounded-full bg-blue-600" aria-hidden="true"></span>Unread</span>
                                                    @else
                                                        <span class="text-xs font-medium text-slate-500">Read</span>
                                                    @endif
                                                </div>
                                                @if ($notice['work_order_number'])
                                                    <div class="mt-3">
                                                        <p class="text-sm font-bold text-blue-800">{{ $notice['work_order_number'] }}</p>
                                                        @if ($notice['work_order_subject'])<p class="mt-0.5 text-sm font-semibold text-slate-900">{{ $notice['work_order_subject'] }}</p>@endif
                                                    </div>
                                                @endif

                                                @if ($notice['requester_name'])
                                                    <div class="mt-3 flex items-start gap-2 text-sm">
                                                        <x-icon name="user" size="size-4" class="mt-0.5 text-slate-500" />
                                                        <div>
                                                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Requester</p>
                                                            <p class="font-semibold text-slate-800">{{ $notice['requester_name'] }}</p>
                                                            @if ($notice['requester_meta'])<p class="text-xs text-slate-500">{{ $notice['requester_meta'] }}</p>@endif
                                                        </div>
                                                    </div>
                                                @endif

                                                @if ($notice['location'] || $notice['category'])
                                                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
                                                        @if ($notice['location'])<span class="inline-flex items-center gap-1.5"><x-icon name="map-pin" size="size-4" />{{ $notice['location'] }}</span>@endif
                                                        @if ($notice['category'])<span class="status-badge status-neutral">{{ $notice['category'] }}</span>@endif
                                                    </div>
                                                @endif

                                                <p class="mt-3 text-sm leading-6 text-slate-700">{{ $notice['message'] }}</p>
                                                <time class="mt-2 block text-xs text-slate-500" datetime="{{ $notification->created_at?->toIso8601String() }}" title="{{ $notice['exact_time'] }}">{{ $notice['relative_time'] }}</time>
                                            </div>
                                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                                @if ($notice['action_url'])
                                                    <a class="btn btn-secondary !min-h-9 !px-3" href="{{ $notice['action_url'] }}" aria-label="View {{ $notice['work_order_number'] ? 'Work Order '.$notice['work_order_number'] : 'notification details' }}"><x-icon name="eye" size="size-4" />{{ $notice['work_order_number'] ? 'View Work Order' : 'View' }}</a>
                                                @endif
                                                @if ($notice['is_unread'])
                                                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="btn btn-ghost !min-h-9 !px-3" type="submit">Mark as read</button></form>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
        <div class="mt-6">{{ $notifications->links() }}</div>
    @else
        <div class="mt-7">
            @if ($tab === 'unread' && $type === '' && $date === '' && $search === '')
                <x-empty-state title="You're all caught up" description="There are no unread notifications right now." icon="check-circle" />
            @elseif ($type !== '' || $date !== '' || $search !== '')
                <x-empty-state title="No matching notifications" description="Try changing or resetting your filters." icon="filter" />
            @else
                <x-empty-state title="No notifications yet" description="Work Order updates, assignments, and requested actions will appear here." icon="bell" />
            @endif
        </div>
    @endif
@endsection
