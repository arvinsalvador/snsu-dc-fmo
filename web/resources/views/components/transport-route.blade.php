@props(['from', 'to', 'compact' => false])
@if ($compact)
    <p {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-1.5 text-sm font-medium text-slate-900']) }}><span>{{ $from }}</span><x-icon name="arrow-right" size="size-4" class="text-slate-400" /><span>{{ $to }}</span></p>
@else
    <div {{ $attributes->merge(['class' => 'grid grid-cols-[auto_1fr] gap-x-4']) }}>
        <div class="flex flex-col items-center py-1.5" aria-hidden="true">
            <span class="size-3 rounded-full border-2 border-blue-600 bg-white"></span>
            <span class="my-1 w-px grow border-l-2 border-dashed border-slate-300"></span>
            <x-icon name="map-pin" size="size-5" class="text-blue-700" />
        </div>
        <div class="space-y-4">
            <div><p class="detail-label">From</p><p class="text-base font-semibold text-slate-900">{{ $from }}</p></div>
            <div><p class="detail-label">To</p><p class="text-base font-semibold text-slate-900">{{ $to }}</p></div>
        </div>
    </div>
@endif
