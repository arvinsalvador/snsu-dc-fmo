@props(['code', 'title', 'message', 'icon' => 'warning'])
<div class="mx-auto max-w-xl py-8 text-center sm:py-16">
    <div class="mx-auto grid size-16 place-items-center rounded-2xl bg-blue-100 text-blue-800"><x-icon :name="$icon" size="size-8" /></div>
    <p class="mt-6 text-sm font-semibold uppercase tracking-[0.18em] text-blue-700">Error {{ $code }}</p>
    <h1 class="mt-2">{{ $title }}</h1>
    <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-600">{{ $message }}</p>
    <div class="mt-7 flex flex-wrap justify-center gap-3">
        @auth<a class="btn btn-primary" href="{{ route('dashboard') }}"><x-icon name="dashboard" />Return to dashboard</a>@else<a class="btn btn-primary" href="{{ route('login') }}">Return to sign in</a>@endauth
        <button class="btn btn-secondary" type="button" onclick="history.back()"><x-icon name="chevron-left" />Go back</button>
    </div>
</div>
