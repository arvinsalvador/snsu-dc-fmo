@props(['label', 'value', 'href' => null, 'hint' => null])
@if($href)<a href="{{ $href }}" class="app-card block p-5 transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm">@else<div class="app-card p-5">@endif
    <p class="text-sm font-medium text-slate-600">{{ $label }}</p><p class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>@if($hint)<p class="mt-2 text-xs text-slate-500">{{ $hint }}</p>@endif
@if($href)</a>@else</div>@endif
