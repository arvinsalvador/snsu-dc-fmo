@props([
    'label' => 'More actions',
    'align' => 'right',
    'width' => 'w-56',
])

@php($menuId = 'action-menu-'.Str::uuid())

<div class="relative inline-flex" data-action-menu data-action-menu-align="{{ $align }}">
    <button
        type="button"
        class="icon-btn"
        aria-label="{{ $label }}"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="{{ $menuId }}"
        data-action-menu-trigger
    >
        @isset($trigger)
            {{ $trigger }}
        @else
            <span aria-hidden="true" class="text-lg font-bold leading-none tracking-widest">•••</span>
        @endisset
    </button>

    <div
        id="{{ $menuId }}"
        class="fixed z-50 hidden {{ $width }} rounded-xl border border-slate-200 bg-white p-2 shadow-xl"
        role="menu"
        aria-hidden="true"
        data-action-menu-panel
    >
        {{ $slot }}
    </div>
</div>
