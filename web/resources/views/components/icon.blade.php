@props(['name', 'size' => 'size-5'])

<svg
    {{ $attributes->merge(['class' => $size.' shrink-0']) }}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
>
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
            @break
        @case('clipboard')
            <rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4.5V3h6v1.5M9 9h6M9 13h6M9 17h4" />
            @break
        @case('tasks')
            <path d="m4 7 2 2 4-4M4 14l2 2 4-4M13 7h7M13 14h7M4 21h16" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" />
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9" /><path d="m8 12 2.5 2.5L16 9" />
            @break
        @case('assignment')
            <circle cx="9" cy="8" r="3" /><path d="M3.5 20v-2a5.5 5.5 0 0 1 11 0v2M17 8v6M14 11h6" />
            @break
        @case('assessment')
            <rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4.5V3h6v1.5m-6 8 2 2 4-5" />
            @break
        @case('play')
            <circle cx="12" cy="12" r="9" /><path d="m10 8 6 4-6 4Z" />
            @break
        @case('shield-check')
            <path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6Z" /><path d="m9 12 2 2 4-4" />
            @break
        @case('users')
            <circle cx="9" cy="8" r="3" /><circle cx="17" cy="9" r="2.5" /><path d="M3.5 20v-2a5.5 5.5 0 0 1 11 0v2M14 15.5a4.5 4.5 0 0 1 6.5 4" />
            @break
        @case('wrench')
            <path d="M14.5 6.5a4 4 0 0 0-5-5l2.2 2.2-2.8 2.8-2.2-2.2a4 4 0 0 0 5 5L20 18l-2 2-8.3-8.3" />
            @break
        @case('building')
            <path d="M4 21V5l8-3v19M12 8h8v13M7 7h2M7 11h2M7 15h2M15 11h2M15 15h2M2 21h20" />
            @break
        @case('map-pin')
            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" />
            @break
        @case('tag')
            <path d="M20 13 13 20 4 11V4h7Z" /><circle cx="8.5" cy="8.5" r="1" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
            @break
        @case('user')
            <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />
            @break
        @case('lock')
            <rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" />
            @break
        @case('chart')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18" />
            @break
        @case('logout')
            <path d="M10 4H4v16h6M14 8l4 4-4 4M18 12H8" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('eye')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" /><circle cx="12" cy="12" r="2.5" />
            @break
        @case('filter')
            <path d="M4 5h16l-6 7v6l-4 2v-8Z" />
            @break
        @case('download')
            <path d="M12 3v12m-4-4 4 4 4-4M4 20h16" />
            @break
        @case('printer')
            <path d="M7 8V3h10v5M7 17H4V9h16v8h-3M7 14h10v7H7Z" /><path d="M17 11h.01" />
            @break
        @case('camera')
            <path d="M4 7h3l1.5-2h7L17 7h3v12H4Z" /><circle cx="12" cy="13" r="3.5" />
            @break
        @case('qr-code')
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><path d="M6 6h1v1H6zM17 6h1v1h-1zM6 17h1v1H6zM14 14h3v3h-3zM19 14h2v2M14 19h2v2M18 18h3v3" />
            @break
        @case('upload')
            <path d="M12 17V5m-4 4 4-4 4 4M4 20h16" />
            @break
        @case('pencil')
            <path d="m4 20 4.5-1 10-10-3.5-3.5-10 10ZM13.5 7l3.5 3.5" />
            @break
        @case('trash')
            <path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6" />
            @break
        @case('info')
            <circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" />
            @break
        @case('warning')
            <path d="M12 3 2.5 20h19ZM12 9v4M12 17h.01" />
            @break
        @case('mail')
            <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" />
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 10h18" />
            @break
        @case('chevron-left')
            <path d="m15 18-6-6 6-6" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('floor')
            <path d="m3 8 9-5 9 5-9 5ZM3 13l9 5 9-5M3 17l9 5 9-5" />
            @break
        @default
            <circle cx="12" cy="12" r="9" />
    @endswitch
</svg>
