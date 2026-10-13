@props(['icon' => null, 'expanded' => false])

<summary
    data-collapsible-summary
    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
    {{ $attributes->class('flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-lg px-2 py-2 font-semibold transition-colors hover:bg-slate-100/70 hover:text-slate-950') }}
>
    <span class="flex min-w-0 items-center gap-2">
        @if ($icon)<x-icon :name="$icon" class="text-blue-700" />@endif
        <span>{{ $slot }}</span>
    </span>
    <x-icon name="chevron-right" size="size-5" data-collapsible-chevron class="shrink-0 text-slate-400 transition-transform duration-200" />
</summary>
