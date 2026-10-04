@extends('layouts.app')
@section('title', 'Skills')
@section('content')
    <x-page-header title="Skills" description="Manage the skills used to identify suitable FMO personnel." />

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-56 flex-1">
            Search skills
            <input name="search" value="{{ $search }}" placeholder="Skill name">
        </label>
        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-ghost" href="{{ route('skills.index') }}">Reset</a>
    </form>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Status</th>
                        <th>Personnel</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($skills as $skill)
                        <tr>
                            <td>
                                <span class="font-semibold text-slate-900">{{ $skill->name }}</span>
                                <p class="mt-1 text-sm text-slate-500">{{ $skill->description }}</p>
                            </td>
                            <td><x-status-badge :status="$skill->is_active ? 'ACTIVE' : 'INACTIVE'" /></td>
                            <td>{{ $skill->personnel_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-3">
                                    @can('skills.update')
                                        <form method="POST" action="{{ route('skills.update', $skill) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="name" value="{{ $skill->name }}">
                                            <input type="hidden" name="description" value="{{ $skill->description }}">
                                            <input type="hidden" name="is_active" value="{{ $skill->is_active ? 0 : 1 }}">
                                            <button class="btn btn-ghost !px-2" type="submit">{{ $skill->is_active ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                    @endcan
                                    @can('skills.delete')
                                        @if (! $skill->personnel_count)
                                            <form method="POST" action="{{ route('skills.destroy', $skill) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-ghost !px-2 !text-rose-700" type="submit">Delete</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state title="No skills found" description="Try a different search or add the first skill." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('skills.create')
            <form class="section-card self-start" method="POST" action="{{ route('skills.store') }}">
                @csrf
                <h2 class="text-lg">Add skill</h2>
                <label class="mt-4 block">Name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label class="mt-4 block">Description<textarea name="description">{{ old('description') }}</textarea></label>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary mt-5" type="submit">Create skill</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $skills->links() }}</div>
@endsection
