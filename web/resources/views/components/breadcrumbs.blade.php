@props(['items' => []])
<nav aria-label="Breadcrumb" class="mb-4 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
    @foreach ($items as $label => $url)
        @if (! $loop->last && $url)
            <a class="font-medium text-blue-800 hover:underline" href="{{ $url }}">{{ $label }}</a>
            <x-icon name="chevron-right" size="size-4" />
        @else
            <span aria-current="page">{{ is_int($label) ? $url : $label }}</span>
        @endif
    @endforeach
</nav>
