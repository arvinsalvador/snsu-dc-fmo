@extends('layouts.app')
@section('title', 'Users')
@section('content')
    <x-page-header title="Users" description="Review application accounts, institutional types, statuses and assigned roles." />
    <form method="GET" class="filter-bar mt-6"><label class="min-w-64 flex-1">Search users<input name="search" value="{{ $search }}" placeholder="Name or email"></label><button class="btn btn-primary" type="submit"><x-icon name="search" />Search</button><a class="btn btn-ghost" href="{{ route('users.index') }}">Reset</a></form>
    <div class="table-wrap mobile-card-table mt-6"><table><thead><tr><th>User</th><th>Institutional type</th><th>Status</th><th>Roles</th><th class="text-right">Action</th></tr></thead><tbody>
        @forelse ($users as $user)
            <tr><td><div class="flex items-center gap-3"><x-avatar :name="$user->name" /><div><p class="font-semibold text-slate-900">{{ $user->name }}</p><p class="text-xs text-slate-500">{{ $user->email }}</p></div></div></td><td data-label="Institutional type">{{ ucfirst($user->user_type ?? 'Unknown') }}</td><td data-label="Status"><x-status-badge :status="$user->status" /></td><td data-label="Roles"><div class="flex flex-wrap gap-1">@forelse ($user->getRoleNames() as $role)<span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ $role }}</span>@empty<span class="text-slate-500">No role</span>@endforelse</div></td><td data-label="Action" class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route('users.show', $user) }}"><x-icon name="eye" size="size-4" />Details</a></td></tr>
        @empty
            <tr><td colspan="5"><x-empty-state title="No users found" description="Try a different name or email." icon="users" /></td></tr>
        @endforelse
    </tbody></table></div>
    <div class="mt-5">{{ $users->links() }}</div>
@endsection
