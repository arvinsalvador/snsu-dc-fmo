@extends('layouts.app')
@section('title', 'Users')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Users</h1>
<form method="GET" class="mb-4 flex gap-2"><input class="w-full max-w-sm rounded border p-2" name="search" value="{{ $search }}" placeholder="Search name or email"><button class="rounded bg-blue-700 px-4 py-2 text-white">Search</button></form>
<div class="overflow-x-auto rounded-lg border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Name</th><th class="p-3">Email</th><th class="p-3">Category</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead><tbody>
@forelse ($users as $user)<tr class="border-t"><td class="p-3">{{ $user->name }}</td><td class="p-3">{{ $user->email }}</td><td class="p-3">{{ ucfirst($user->user_type ?? '—') }}</td><td class="p-3">{{ $user->status->value }}</td><td class="p-3"><a class="text-blue-700 underline" href="{{ route('users.show', $user) }}">Details</a></td></tr>
@empty <tr><td class="p-4" colspan="5">No users found.</td></tr> @endforelse
</tbody></table></div><div class="mt-4">{{ $users->links() }}</div>
@endsection
