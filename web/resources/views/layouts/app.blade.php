<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SNSU FMO') | SNSU FMO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @auth
        @php($user = auth()->user())
        <div data-sidebar-backdrop class="fixed inset-0 z-30 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"></div>

        <aside data-sidebar class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-slate-950 px-4 py-5 shadow-2xl transition-transform duration-200 lg:translate-x-0" aria-label="Primary navigation">
            <div class="flex items-start justify-between gap-3 px-2">
                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 text-white">
                    <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-blue-600 text-xs font-bold ring-1 ring-white/20">SNSU</span>
                    <span class="min-w-0">
                        <span class="block truncate font-semibold leading-tight">Facilities Management</span>
                        <span class="mt-0.5 block text-xs text-slate-400">Del Carmen Campus</span>
                    </span>
                </a>
                <button data-sidebar-toggle class="icon-btn border-white/10 text-slate-300 hover:bg-white/10 hover:text-white lg:hidden" type="button" aria-label="Close navigation" aria-expanded="true">
                    <x-icon name="close" />
                </button>
            </div>

            <nav class="mt-6 flex-1 overflow-y-auto pb-6">
                <p class="nav-heading !mt-0">Overview</p>
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}" href="{{ route('dashboard') }}"><x-icon name="dashboard" /><span>Dashboard</span></a>

                <p class="nav-heading">Requests &amp; Work Orders</p>
                @can('work_orders.view_own')
                    <a class="nav-link {{ request()->routeIs('work-orders.mine') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.mine') }}"><x-icon name="clipboard" /><span>My Requests</span></a>
                @endcan
                @can('work_orders.view_assigned')
                    <a class="nav-link {{ request()->routeIs('work-orders.my-tasks') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.my-tasks') }}"><x-icon name="tasks" /><span>My Tasks</span></a>
                @endcan
                @can('work_orders.view_all')
                    <a class="nav-link {{ request()->routeIs('work-orders.index') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.index') }}"><x-icon name="clipboard" /><span>All Work Orders</span></a>
                @endcan
                @can('work_orders.screen')
                    <a class="nav-link {{ request()->is('work-orders/queue/screening') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.queue', 'screening') }}"><x-icon name="search" /><span>For Screening</span></a>
                @endcan
                @can('work_orders.approve')
                    <a class="nav-link {{ request()->is('work-orders/queue/approval') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.queue', 'approval') }}"><x-icon name="check-circle" /><span>For Approval</span></a>
                @endcan
                @if ($user->can('work_orders.assign') || $user->can('work_orders.assign_direct'))
                    <a class="nav-link {{ request()->routeIs('work-orders.assignment-queue') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.assignment-queue') }}"><x-icon name="assignment" /><span>For Assignment</span></a>
                @endif
                @can('work_orders.view_assessments')
                    <a class="nav-link {{ request()->routeIs('work-orders.assessment-queue') ? 'nav-link-active' : '' }}" href="{{ route('work-orders.assessment-queue') }}"><x-icon name="assessment" /><span>Assessment Review</span></a>
                @endcan
                @can('work_orders.view_execution')
                    <a class="nav-link {{ request()->routeIs('work-orders.execution-queue') && request()->route('queue') === 'active' ? 'nav-link-active' : '' }}" href="{{ route('work-orders.execution-queue', 'active') }}"><x-icon name="play" /><span>Active Work</span></a>
                    <a class="nav-link {{ request()->routeIs('work-orders.execution-queue') && request()->route('queue') === 'verification' ? 'nav-link-active' : '' }}" href="{{ route('work-orders.execution-queue', 'verification') }}"><x-icon name="shield-check" /><span>For Verification</span></a>
                @endcan

                @if ($user->can('personnel.view') || $user->can('skills.view') || $user->can('buildings.view') || $user->can('campuses.view') || $user->can('locations.view') || $user->can('work_order_categories.view'))
                    <p class="nav-heading">Management</p>
                    @can('personnel.view')
                        <a class="nav-link {{ request()->routeIs('personnel.*') ? 'nav-link-active' : '' }}" href="{{ route('personnel.index') }}"><x-icon name="users" /><span>FMO Staff</span></a>
                    @endcan
                    @can('skills.view')
                        <a class="nav-link {{ request()->routeIs('skills.*') ? 'nav-link-active' : '' }}" href="{{ route('skills.index') }}"><x-icon name="wrench" /><span>Skills</span></a>
                    @endcan
                    @if ($user->can('buildings.view') || $user->can('campuses.view') || $user->can('locations.view'))
                        <a class="nav-link {{ request()->routeIs('locations.*') || request()->routeIs('campuses.*') || request()->routeIs('buildings.*') || request()->routeIs('floors.*') ? 'nav-link-active' : '' }}" href="{{ route('locations.index') }}"><x-icon name="map-pin" /><span>Locations</span></a>
                    @endif
                    @can('work_order_categories.view')
                        <a class="nav-link {{ request()->routeIs('work-order-categories.*') ? 'nav-link-active' : '' }}" href="{{ route('work-order-categories.index') }}"><x-icon name="tag" /><span>Work Categories</span></a>
                    @endcan
                @endif

                @if ($user->can('reports.view_work_orders') || $user->can('work_orders.view_own') || $user->can('work_orders.view_assigned'))
                    <p class="nav-heading">Reports</p>
                    <a class="nav-link {{ request()->routeIs('reports.*') ? 'nav-link-active' : '' }}" href="{{ route('reports.history') }}"><x-icon name="chart" /><span>Work History</span></a>
                @endif

                @if ($user->can('users.view_pending_registrations') || $user->can('users.view') || $user->can('roles.view'))
                    <p class="nav-heading">Administration</p>
                    @can('users.view_pending_registrations')
                        <a class="nav-link {{ request()->routeIs('registrations.*') ? 'nav-link-active' : '' }}" href="{{ route('registrations.index') }}"><x-icon name="clock" /><span>Registration Approvals</span></a>
                    @endcan
                    @can('users.view')
                        <a class="nav-link {{ request()->routeIs('users.*') ? 'nav-link-active' : '' }}" href="{{ route('users.index') }}"><x-icon name="user" /><span>Users</span></a>
                    @endcan
                    @can('roles.view')
                        <a class="nav-link {{ request()->routeIs('roles.*') ? 'nav-link-active' : '' }}" href="{{ route('roles.index') }}"><x-icon name="lock" /><span>Roles &amp; Permissions</span></a>
                    @endcan
                @endif
            </nav>

            <div class="border-t border-white/10 pt-3">
                <a class="nav-link {{ request()->routeIs('notifications.*') ? 'nav-link-active' : '' }}" href="{{ route('notifications.index') }}"><x-icon name="bell" /><span>Notifications</span></a>
                @can('profiles.view_own')
                    <a class="nav-link {{ request()->routeIs('profile.*') ? 'nav-link-active' : '' }}" href="{{ route('profile.show') }}"><x-icon name="user" /><span>My Profile</span></a>
                @endcan
            </div>
        </aside>

        <div class="min-h-screen lg:pl-72">
            <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div class="flex h-16 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                    <div class="flex min-w-0 items-center gap-3">
                        <button data-sidebar-toggle class="icon-btn lg:hidden" type="button" aria-label="Open navigation" aria-expanded="false"><x-icon name="menu" /></button>
                        <div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">@yield('title', 'SNSU FMO')</p><p class="hidden text-xs text-slate-500 sm:block">Facilities Management Office</p></div>
                    </div>
                    <div class="ml-auto flex items-center gap-2 sm:gap-3">
                        <a class="icon-btn relative" href="{{ route('notifications.index') }}" aria-label="Notifications">
                            <x-icon name="bell" />
                            @if (($unreadCount = $user->unreadNotifications()->count()) > 0)
                                <span class="absolute -right-1 -top-1 grid min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">{{ $unreadCount }}</span>
                            @endif
                        </a>
                        @can('profiles.view_own')
                            <a href="{{ route('profile.show') }}" class="hidden items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-slate-50 sm:flex">
                                <x-avatar :name="$user->name" size="sm" />
                                <span class="hidden text-left md:block"><span class="block text-sm font-semibold text-slate-800">{{ $user->name }}</span><span class="block text-xs text-slate-500">{{ $user->getRoleNames()->first() ?? ucfirst($user->user_type) }}</span></span>
                            </a>
                        @endcan
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="btn btn-secondary !min-h-9 !px-3" type="submit"><x-icon name="logout" size="size-4" /><span class="hidden sm:inline">Sign out</span></button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                @if (session('success'))
                    <x-alert type="success" class="mb-6">{{ session('success') }}</x-alert>
                @endif
                @if (session('warning'))
                    <x-alert type="warning" class="mb-6">{{ session('warning') }}</x-alert>
                @endif
                @if ($errors->any())
                    <x-alert type="danger" title="Please review the highlighted information." class="mb-6">
                        <ul class="list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
                @yield('content')
            </main>
        </div>
    @else
        <main class="min-h-screen bg-slate-100 px-4 py-8 sm:grid sm:place-items-center">
            <div class="w-full max-w-5xl">
                @if ($errors->any())
                    <x-alert type="danger" title="Please review the highlighted information." class="mx-auto mb-5 max-w-lg">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
                @yield('content')
            </div>
        </main>
    @endauth
    <x-confirm-dialog />
</body>
</html>
