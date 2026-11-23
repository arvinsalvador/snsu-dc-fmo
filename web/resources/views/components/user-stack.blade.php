@props(['users', 'max' => 3, 'empty' => 'Unassigned'])
@php
    $list = collect($users)->filter()->values();
    $shown = $list->take($max);
    $more = $list->count() - $shown->count();
@endphp
@if ($list->isEmpty())
    <span class="text-sm text-slate-400">{{ $empty }}</span>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
        <span class="flex -space-x-2">
            @foreach ($shown as $member)
                <x-user-avatar :user="$member" size="xs" class="ring-2 !ring-white" title="{{ $member->name }}" />
            @endforeach
            @if ($more > 0)<span class="inline-grid size-6 place-items-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-600 ring-2 ring-white">+{{ $more }}</span>@endif
        </span>
        <span class="text-sm text-slate-700">{{ $shown->pluck('name')->join(', ') }}@if ($more > 0) and {{ $more }} more @endif</span>
    </span>
@endif
