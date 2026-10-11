@props(['name', 'size' => 'md'])
@php
    $initials = collect(preg_split('/\s+/', trim($name)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->join('');
    $sizeClass = $size === 'lg' ? 'size-14 text-base' : ($size === 'sm' ? 'size-8 text-xs' : 'size-10 text-sm');
@endphp
<span {{ $attributes->merge(['class' => $sizeClass.' inline-grid shrink-0 place-items-center rounded-full bg-blue-100 font-bold text-blue-800 ring-1 ring-blue-200']) }} aria-hidden="true">{{ $initials ?: '?' }}</span>
