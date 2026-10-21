@extends('layouts.app')
@section('title', 'Work Order Categories')
@section('content')
    <x-page-header title="Work Order Categories" description="Manage the work types requesters can select." />

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-56 flex-1">
            Search categories
            <input name="search" value="{{ $search }}" placeholder="Category name">
        </label>
        <button class="btn btn-primary" type="submit"><x-icon name="search" />Search</button>
        <a class="btn btn-ghost" href="{{ route('work-order-categories.index') }}">Reset</a>
    </form>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Relevant personnel skills</th>
                        <th>Status</th>
                        <th>Requests</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categories as $category)
                        <tr>
                            <td>
                                <span class="font-semibold text-slate-900">{{ $category->name }}</span>
                                <p class="mt-1 text-sm text-slate-500">{{ $category->description }}</p>
                            </td>
                            <td>
                                <div class="flex max-w-md flex-wrap gap-1.5">
                                    @forelse ($category->relevantSkills as $skill)
                                        <span class="status-badge status-open">{{ $skill->name }}</span>
                                    @empty
                                        <span class="text-sm font-medium text-amber-700">No skill mapping configured</span>
                                    @endforelse
                                </div>
                            </td>
                            <td><x-status-badge :status="$category->is_active ? 'ACTIVE' : 'INACTIVE'" /></td>
                            <td>{{ $category->work_orders_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-3">
                                    @can('work_order_categories.update')
                                        <details class="relative">
                                            <summary class="btn btn-ghost !min-h-9 !px-2">Edit</summary>
                                            <form class="absolute right-0 z-30 mt-2 w-[min(32rem,calc(100vw-2rem))] space-y-4 rounded-xl border border-slate-200 bg-white p-5 text-left shadow-xl" method="POST" action="{{ route('work-order-categories.update', $category) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="relevant_skill_ids_present" value="1">
                                                <input type="hidden" name="is_active" value="{{ $category->is_active ? 1 : 0 }}">
                                                <label>Name <span class="text-rose-600">*</span><input name="name" value="{{ $category->name }}" required></label>
                                                <label>Description<textarea name="description">{{ $category->description }}</textarea></label>
                                                <label>Display order<input name="display_order" type="number" min="0" value="{{ $category->display_order }}"></label>
                                                <fieldset>
                                                    <legend class="text-sm font-semibold text-slate-800">Relevant Personnel Skills</legend>
                                                    <p class="mt-1 text-xs text-slate-500">Requesters will only see active personnel who have at least one checked skill.</p>
                                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                                        @foreach ($skills as $skill)
                                                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium">
                                                                <input type="checkbox" name="relevant_skill_ids[]" value="{{ $skill->id }}" @checked($category->relevantSkills->contains('id', $skill->id))>
                                                                {{ $skill->name }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </fieldset>
                                                <button class="btn btn-primary" type="submit"><x-icon name="check-circle" />Save category</button>
                                            </form>
                                        </details>
                                    @endcan
                                    @if (($category->is_active && auth()->user()->can('work_order_categories.manage_status')) || (! $category->is_active && auth()->user()->can('work_order_categories.update')))
                                        <form method="POST" action="{{ route('work-order-categories.update', $category) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="name" value="{{ $category->name }}">
                                            <input type="hidden" name="description" value="{{ $category->description }}">
                                            <input type="hidden" name="display_order" value="{{ $category->display_order }}">
                                            <input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}">
                                            <button class="btn btn-ghost !px-2" type="submit">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                    @endif
                                    @can('work_order_categories.delete')
                                        @if (! $category->work_orders_count)
                                            <form method="POST" action="{{ route('work-order-categories.destroy', $category) }}" data-confirm="Delete this unused Work Order category? This action cannot be undone.">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-ghost !px-2 !text-rose-700" type="submit"><x-icon name="trash" size="size-4" />Delete</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty-state title="No categories found" description="Try a different search or add the first category." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('work_order_categories.create')
            <form class="section-card self-start" method="POST" action="{{ route('work-order-categories.store') }}">
                @csrf
                <h2 class="text-lg">Add category</h2>
                <label class="mt-4 block">Name <span class="text-rose-600">*</span><input name="name" value="{{ old('name') }}" required></label>
                <label class="mt-4 block">Description<textarea name="description">{{ old('description') }}</textarea></label>
                <label class="mt-4 block">Display order<input name="display_order" type="number" min="0" value="{{ old('display_order', 0) }}"></label>
                <input type="hidden" name="relevant_skill_ids_present" value="1">
                <fieldset class="mt-4">
                    <legend class="text-sm font-semibold text-slate-800">Relevant Personnel Skills</legend>
                    <p class="mt-1 text-xs text-slate-500">Choose the skills used to match personnel for this Work Type.</p>
                    <div class="mt-3 grid gap-2">
                        @foreach ($skills as $skill)
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium">
                                <input type="checkbox" name="relevant_skill_ids[]" value="{{ $skill->id }}" @checked(in_array($skill->id, old('relevant_skill_ids', [])))>
                                {{ $skill->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('relevant_skill_ids')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary mt-5" type="submit"><x-icon name="plus" />Create category</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $categories->links() }}</div>
@endsection
