@extends('layouts.app')
@section('title', 'Registration Approvals')
@section('content')
    @php
        $tabs = [
            'pending' => 'Pending',
            'needs_correction' => 'Needs Correction',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'all' => 'All',
        ];
        $selectedLabel = $tabs[$filters['status']];
        $clearUrl = route('registrations.index', $filters['status'] === 'pending' ? [] : ['status' => $filters['status']]);
    @endphp

    <x-page-header title="Registration Approvals" description="Review account requests and confirm institutional access." />

    <div class="mt-5 flex flex-wrap gap-2" aria-label="Registration summary">
        <span class="status-badge status-pending">Pending: {{ $statusCounts['pending'] }}</span>
        <span class="status-badge status-success">Approved: {{ $statusCounts['approved'] }}</span>
        <span class="status-badge status-danger">Rejected: {{ $statusCounts['rejected'] }}</span>
    </div>

    <nav class="mt-6 overflow-x-auto border-b border-slate-200" aria-label="Registration status">
        <div class="flex min-w-max gap-1">
            @foreach ($tabs as $key => $label)
                @php($tabUrl = route('registrations.index', [...$queryString, 'status' => $key]))
                <a
                    href="{{ $tabUrl }}"
                    class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition {{ $filters['status'] === $key ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}"
                    @if ($filters['status'] === $key) aria-current="page" @endif
                >
                    {{ $label }}
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $filters['status'] === $key ? 'bg-blue-100 text-blue-800' : 'bg-slate-100 text-slate-600' }}">{{ $statusCounts[$key] }}</span>
                </a>
            @endforeach
        </div>
    </nav>

    <form class="section-card mt-5" method="GET" data-registration-filters>
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">
                Search applicants
                <input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Search applicant name or email...">
            </label>
            <label class="xl:col-span-2">
                Institutional Type
                <select name="type">
                    <option value="">All Types</option>
                    @foreach ($institutionalTypes as $type)
                        <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ str($type)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="xl:col-span-2">
                Registered
                <select name="registered" data-registration-period>
                    <option value="any" @selected($filters['registered'] === 'any')>Any Date</option>
                    <option value="today" @selected($filters['registered'] === 'today')>Today</option>
                    <option value="last_7_days" @selected($filters['registered'] === 'last_7_days')>Last 7 Days</option>
                    <option value="last_30_days" @selected($filters['registered'] === 'last_30_days')>Last 30 Days</option>
                    <option value="custom" @selected($filters['registered'] === 'custom')>Custom Range</option>
                </select>
            </label>
            <label class="xl:col-span-2">
                Sort
                <select name="sort">
                    <option value="newest" @selected($filters['sort'] === 'newest')>Newest Registered</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest Registered</option>
                    <option value="applicant_asc" @selected($filters['sort'] === 'applicant_asc')>Applicant A–Z</option>
                    <option value="applicant_desc" @selected($filters['sort'] === 'applicant_desc')>Applicant Z–A</option>
                </select>
            </label>
            <label class="xl:col-span-2">
                Per Page
                <select name="per_page">
                    @foreach ([20, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="mt-5 grid gap-5 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 {{ $filters['registered'] === 'custom' ? '' : 'hidden' }}" data-registration-custom-dates>
            <label>
                From
                <input type="date" name="from" value="{{ $filters['from'] }}" @disabled($filters['registered'] !== 'custom') data-registration-date-field>
            </label>
            <label>
                To
                <input type="date" name="to" value="{{ $filters['to'] }}" @disabled($filters['registered'] !== 'custom') data-registration-date-field>
            </label>
        </div>

        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
            <a class="btn btn-secondary justify-center" href="{{ $clearUrl }}">Clear Filters</a>
            <button class="btn btn-primary justify-center" type="submit"><x-icon name="filter" />Apply Filters</button>
        </div>
    </form>

    <div class="mt-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <p class="font-semibold text-slate-900">
            {{ number_format($users->total()) }} {{ str($selectedLabel)->lower() }} {{ str('registration')->plural($users->total()) }}
        </p>
        @if ($users->total() > 0)
            <p class="text-sm text-slate-500">Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }}</p>
        @endif
    </div>

    @if ($users->isEmpty())
        <div class="mt-5">
            @if ($filters['status'] === 'pending' && ! $hasActiveFilters)
                <x-empty-state title="No pending registrations" description="There are currently no account requests waiting for review." icon="clock" />
            @else
                <x-empty-state title="No registrations found" description="Try changing your search or filters." icon="search">
                    <a class="btn btn-secondary mt-4" href="{{ $clearUrl }}">Clear Filters</a>
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="table-wrap mobile-card-table mt-5">
            <table>
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Institutional Type</th>
                        <th>Registered</th>
                        <th>Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <x-avatar :name="$user->name" />
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-900">{{ $user->name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Institutional Type">{{ str($user->user_type)->headline() }}</td>
                            <td data-label="Registered">
                                <span class="block whitespace-nowrap font-medium text-slate-800">{{ $user->created_at->timezone('Asia/Manila')->format('M j, Y') }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $user->created_at->timezone('Asia/Manila')->format('g:i A') }}</span>
                            </td>
                            <td data-label="Status"><x-status-badge :status="$user->status" /></td>
                            <td data-label="Action" class="text-right">
                                <a class="btn {{ $user->status === \App\Enums\AccountStatus::Pending ? 'btn-primary' : 'btn-secondary' }} w-full !min-h-9 !px-3 sm:w-auto" href="{{ route('registrations.show', ['user' => $user, 'return_to' => $returnPath]) }}">
                                    <x-icon name="eye" size="size-4" />
                                    {{ $user->status === \App\Enums\AccountStatus::Pending ? 'Review' : 'View' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-500">Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ $users->total() }} registrations</p>
            {{ $users->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
