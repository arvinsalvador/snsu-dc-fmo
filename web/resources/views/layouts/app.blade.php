<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'SNSU-DC FMO') · SNSU-DC FMO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b bg-white">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-4">
            <a class="font-semibold" href="{{ route('dashboard') }}">SNSU-DC FMO</a>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                @auth
                    <a href="{{ route('dashboard') }}">Home</a>
                    @can('users.view_pending_registrations')<a href="{{ route('registrations.index') }}">Registrations</a>@endcan
                    @can('users.view')<a href="{{ route('users.index') }}">Users</a>@endcan
                    @can('roles.view')<a href="{{ route('roles.index') }}">Roles</a>@endcan
                    <a href="{{ route('registration.status') }}">Account</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf <button type="submit">Sign out</button></form>
                @else
                    <a href="{{ route('login') }}">Sign in</a>
                    <a href="{{ route('register') }}">Register</a>
                @endauth
            </div>
        </nav>
    </header>
    <main class="mx-auto max-w-6xl px-5 py-8">
        @if (session('success'))<p class="mb-5 rounded bg-green-100 p-3 text-green-900">{{ session('success') }}</p>@endif
        @if ($errors->any())
            <div class="mb-5 rounded bg-red-100 p-3 text-red-900"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
</body>
</html>
