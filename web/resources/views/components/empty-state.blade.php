@props(['title' => 'Nothing to show', 'description' => null, 'icon' => 'clipboard'])
<div class="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
    <div class="mx-auto grid size-11 place-items-center rounded-full bg-slate-100 text-slate-500"><x-icon :name="$icon" /></div>
    <h3 class="mt-3 font-semibold text-slate-900">{{ $title }}</h3>
    @if ($description)<p class="mx-auto mt-1 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p>@endif
    {{ $slot }}
</div>
