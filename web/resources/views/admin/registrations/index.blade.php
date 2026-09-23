@extends('layouts.app')
@section('title', 'Registrations')
@section('content')
<h1 class="mb-5 text-2xl font-semibold">Registrations awaiting review</h1>
<div class="overflow-x-auto rounded-lg border bg-white"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-3">Name</th><th class="p-3">Category</th><th class="p-3">Email</th><th class="p-3">Registered</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead><tbody>
@forelse ($users as $user)<tr class="border-t"><td class="p-3">{{ $user->name }}</td><td class="p-3">{{ ucfirst($user->user_type) }}</td><td class="p-3">{{ $user->email }}</td><td class="p-3">{{ $user->created_at->format('M j, Y') }}</td><td class="p-3">{{ str_replace('_', ' ', $user->status->value) }}</td><td class="p-3"><a class="text-blue-700 underline" href="{{ route('registrations.show', $user) }}">Review</a></td></tr>
@empty <tr><td class="p-4" colspan="6">No registrations awaiting review.</td></tr> @endforelse
</tbody></table></div><div class="mt-4">{{ $users->links() }}</div>
@endsection
