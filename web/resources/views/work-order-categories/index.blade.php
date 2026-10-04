@extends('layouts.app')
@section('title', 'Work Order Categories')
@section('content')
    <x-page-header title="Work Order Categories" description="Manage the work types requesters can select." />

    <form class="filter-bar mt-6" method="GET">
        <label class="min-w-56 flex-1">
            Search categories
            <input name="search" value="{{ $search }}" placeholder="Category name">
        </label>
        <button class="btn btn-primary" type="submit">Search</button>
        <a class="btn btn-ghost" href="{{ route('work-order-categories.index') }}">Reset</a>
    </form>

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
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
                            <td><x-status-badge :status="$category->is_active ? 'ACTIVE' : 'INACTIVE'" /></td>
                            <td>{{ $category->work_orders_count }}</td>
                            <td>
                                <div class="flex flex-wrap gap-3">
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
                                            <form method="POST" action="{{ route('work-order-categories.destroy', $category) }}">
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
                        <tr><td colspan="4"><x-empty-state title="No categories found" description="Try a different search or add the first category." /></td></tr>
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
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary mt-5" type="submit">Create category</button>
            </form>
        @endcan
    </div>
    <div class="mt-5">{{ $categories->links() }}</div>
@endsection
