@extends('layouts.app')
@section('title', 'Home')
@section('content')
<div class="rounded-lg border bg-white p-6"><h1 class="text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h1><p class="mt-2 text-slate-600">Your account is approved. Work order features will be added in later phases.</p></div>
@endsection
