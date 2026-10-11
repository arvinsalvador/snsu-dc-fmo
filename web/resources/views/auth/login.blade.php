@extends('layouts.app')
@section('title', 'Sign In')
@section('content')
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-300/30 lg:grid lg:grid-cols-5">
        <section class="hidden bg-slate-950 p-10 text-white lg:col-span-2 lg:block">
            <div class="grid size-12 place-items-center rounded-xl bg-blue-600 font-bold ring-1 ring-white/20">SNSU</div>
            <p class="mt-12 text-sm font-medium uppercase tracking-[0.18em] text-blue-200">Del Carmen Campus</p>
            <h1 class="mt-3 text-3xl text-white">Facilities Management Office</h1>
            <p class="mt-5 max-w-sm leading-7 text-slate-300">A clear, accountable way to request, monitor and deliver campus facilities services.</p>
        </section>
        <section class="p-6 sm:p-10 lg:col-span-3">
            <div class="mx-auto max-w-md">
                <div class="flex items-center gap-3 lg:hidden"><div class="grid size-10 place-items-center rounded-lg bg-blue-700 text-xs font-bold text-white">SNSU</div><p class="font-semibold text-slate-800">Facilities Management Office</p></div>
                <p class="mt-8 text-sm font-semibold text-blue-700 lg:mt-0">SNSU FMO</p>
                <h1 class="mt-2">Welcome back</h1>
                <p class="mt-2 text-sm text-slate-600">Sign in to access your requests and authorized work queues.</p>
                <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
                    @csrf
                    <label class="block">Email address <span class="text-rose-600">*</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"></label>
                    <label class="block">Password <span class="text-rose-600">*</span><span class="relative block"><input id="login-password" type="password" name="password" required autocomplete="current-password" class="pr-16"><button data-password-toggle="login-password" class="absolute right-2 top-1/2 -translate-y-1/2 rounded px-2 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-50" type="button" aria-pressed="false">Show</button></span></label>
                    <button class="btn btn-primary w-full" type="submit"><x-icon name="lock" />Sign in</button>
                </form>
                <p class="mt-6 text-sm text-slate-600">Need an account? <a class="font-semibold text-blue-800 hover:underline" href="{{ route('register') }}">Request access</a>.</p>
            </div>
        </section>
    </div>
@endsection
