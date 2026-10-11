@extends('layouts.app')
@section('title', 'Registration Approvals')
@section('content')
    <x-page-header title="Registration Approvals" description="Review pending account requests and confirm institutional access." />
    <div class="table-wrap mobile-card-table mt-6"><table><thead><tr><th>Applicant</th><th>Institutional type</th><th>Registered</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
        @forelse ($users as $user)
            <tr>
                <td><div class="flex items-center gap-3"><x-avatar :name="$user->name" /><div><p class="font-semibold text-slate-900">{{ $user->name }}</p><p class="text-xs text-slate-500">{{ $user->email }}</p></div></div></td>
                <td data-label="Institutional type">{{ ucfirst($user->user_type) }}</td>
                <td data-label="Registered">{{ $user->created_at->timezone('Asia/Manila')->format('F j, Y') }}</td>
                <td data-label="Status"><x-status-badge :status="$user->status" /></td>
                <td data-label="Action" class="text-right"><a class="btn btn-primary !min-h-9 !px-3" href="{{ route('registrations.show', $user) }}"><x-icon name="eye" size="size-4" />Review</a></td>
            </tr>
        @empty
            <tr><td colspan="5"><x-empty-state title="No registrations await review" description="New account requests will appear here." icon="clock" /></td></tr>
        @endforelse
    </tbody></table></div>
    <div class="mt-5">{{ $users->links() }}</div>
@endsection
