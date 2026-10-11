@props(['label', 'value', 'href' => null, 'hint' => null, 'icon' => 'chart'])
@if($href)<a href="{{ $href }}" class="app-card block p-5 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm">@else<div class="app-card p-5">@endif
    <div class="flex items-start justify-between gap-3"><p class="text-sm font-medium text-slate-600">{{ $label }}</p><span class="grid size-9 place-items-center rounded-lg bg-blue-50 text-blue-700"><x-icon :name="$icon" size="size-4" /></span></div>
    <p class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
    @if($hint)<p class="mt-2 text-xs leading-5 text-slate-500">{{ $hint }}</p>@endif
@if($href)</a>@else</div>@endif
