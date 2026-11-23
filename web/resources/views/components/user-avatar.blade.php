@props(['user' => null, 'size' => 'md', 'name' => null])
@php
    $label = $user?->name ?? $name ?? '';
    $url = $user?->avatarUrl();
    $sizeClass = match ($size) {
        'xs' => 'size-6',
        'sm' => 'size-8',
        'lg' => 'size-14',
        'xl' => 'size-24',
        default => 'size-10',
    };
@endphp
@if ($url)
    <img {{ $attributes->merge(['class' => $sizeClass.' shrink-0 rounded-full bg-slate-100 object-cover ring-1 ring-slate-200']) }} src="{{ $url }}" alt="Profile picture of {{ $label }}" loading="lazy" decoding="async">
@else
    <x-avatar :name="$label" :size="$size" {{ $attributes->only('class') }} />
@endif
