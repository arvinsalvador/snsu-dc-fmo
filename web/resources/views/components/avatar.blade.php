@props(['name', 'size' => 'md'])
@php
    $sizeClass = match ($size) {
        'xs' => 'size-6 text-[10px]',
        'sm' => 'size-8 text-xs',
        'lg' => 'size-14 text-base',
        'xl' => 'size-24 text-2xl',
        default => 'size-10 text-sm',
    };
@endphp
<span {{ $attributes->merge(['class' => $sizeClass.' inline-grid shrink-0 place-items-center rounded-full bg-blue-100 font-bold text-blue-800 ring-1 ring-blue-200']) }} aria-hidden="true">{{ \App\Support\Initials::from($name) }}</span>
