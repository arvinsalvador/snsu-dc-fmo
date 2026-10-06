@extends('layouts.app')

@section('title', 'Location Management')

@section('content')
    <x-page-header
        title="Location Management"
        description="Manage campuses, buildings, floors, offices, rooms and other campus areas used in Work Orders."
    />

    <div class="mt-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        @can('campuses.view')
            <article class="section-card flex flex-col">
                <p class="text-sm font-semibold text-blue-700">Campuses</p>
                <p class="mt-2 text-sm text-slate-600">Manage university campuses and their availability.</p>
                <p class="mt-5 text-3xl font-semibold text-slate-900">{{ $campusesCount }}</p>
                <a class="btn btn-secondary mt-5" href="{{ route('campuses.index') }}">Manage campuses</a>
            </article>
        @endcan
        @can('buildings.view')
            <article class="section-card flex flex-col">
                <p class="text-sm font-semibold text-blue-700">Buildings</p>
                <p class="mt-2 text-sm text-slate-600">Manage buildings and open their details to maintain their hierarchy.</p>
                <p class="mt-5 text-3xl font-semibold text-slate-900">{{ $buildingsCount }}</p>
                <a class="btn btn-secondary mt-5" href="{{ route('buildings.index') }}">Manage buildings</a>
            </article>
        @endcan
        @can('locations.view')
            <article class="section-card flex flex-col">
                <p class="text-sm font-semibold text-blue-700">Floors</p>
                <p class="mt-2 text-sm text-slate-600">Add and maintain floors from each building’s detail page.</p>
                <p class="mt-5 text-3xl font-semibold text-slate-900">{{ $floorsCount }}</p>
                <a class="btn btn-secondary mt-5" href="{{ route('buildings.index') }}">Find a building</a>
            </article>
            <article class="section-card flex flex-col">
                <p class="text-sm font-semibold text-blue-700">Offices / Rooms / Areas</p>
                <p class="mt-2 text-sm text-slate-600">Manage specific work locations, including building-level and outdoor areas.</p>
                <p class="mt-5 text-3xl font-semibold text-slate-900">{{ $locationsCount }}</p>
                <a class="btn btn-secondary mt-5" href="{{ route('locations.areas') }}">Manage areas</a>
            </article>
        @endcan
    </div>

    <section class="section-card mt-6">
        <h2 class="text-lg">How the hierarchy works</h2>
        <p class="mt-2 text-sm text-slate-600">Campus → Building → optional Floor → optional Office, Room, or Area. Buildings are required on Work Orders; floors and specific areas remain optional for building-wide concerns.</p>
    </section>
@endsection
