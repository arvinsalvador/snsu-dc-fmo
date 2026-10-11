@props(['type' => 'info', 'title' => null])
@php
    [$classes, $icon] = match ($type) {
        'success' => ['border-emerald-200 bg-emerald-50 text-emerald-900', 'check-circle'],
        'warning' => ['border-amber-200 bg-amber-50 text-amber-950', 'warning'],
        'danger', 'error' => ['border-rose-200 bg-rose-50 text-rose-900', 'warning'],
        default => ['border-blue-200 bg-blue-50 text-blue-900', 'info'],
    };
@endphp
<div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border p-4 text-sm '.$classes, 'role' => in_array($type, ['danger', 'error']) ? 'alert' : 'status']) }}>
    <x-icon :name="$icon" class="mt-0.5" />
    <div class="min-w-0 flex-1">
        @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div @class(['mt-1' => $title])>{{ $slot }}</div>
    </div>
</div>
