@props(['title', 'description' => null, 'action' => null])
<div class="flex flex-wrap items-end justify-between gap-4">
    <div><h1>{{ $title }}</h1>@if($description)<p class="mt-1.5 max-w-2xl text-sm text-slate-600">{{ $description }}</p>@endif</div>
    @if($action)<div>{{ $action }}</div>@endif
</div>
