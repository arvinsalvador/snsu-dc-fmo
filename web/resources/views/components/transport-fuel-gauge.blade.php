@props(['level', 'label'])
@php
    $filled = $level ? intdiv($level->percent(), 25) : 0;
    $low = $level && $level->percent() <= 25;
@endphp
<div {{ $attributes }}>
    <p class="detail-label">{{ $label }}</p>
    @if ($level)
        <div class="mt-2 flex items-center gap-1" role="img" aria-label="{{ $label }}: {{ $level->label() }}">
            @foreach (range(1, 4) as $segment)
                <span class="h-3 flex-1 rounded-sm {{ $segment <= $filled ? ($low ? 'bg-amber-500' : 'bg-blue-600') : 'bg-slate-200' }}"></span>
            @endforeach
        </div>
        <p class="mt-1.5 flex items-center justify-between text-sm"><span class="font-semibold text-slate-900">{{ $level->label() }}</span><span class="text-xs text-slate-500">{{ $level->percent() }}%</span></p>
    @else
        <p class="mt-2 text-sm text-slate-400">Not recorded</p>
    @endif
</div>
