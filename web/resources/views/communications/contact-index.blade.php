@extends('layouts.app')
@section('title', $management ? 'FMO Inquiries' : 'Contact FMO')
@section('content')
    <x-page-header
        :title="$management ? 'FMO Inquiries' : 'Contact FMO'"
        :description="$management ? 'Review and respond to general facilities inquiries from authorized users.' : 'Send a general facilities question or inquiry to the FMO.'"
    >
        @if (! $management)
            <x-slot:action><a class="btn btn-primary" href="{{ route('contact-fmo.create') }}"><x-icon name="plus" />New Inquiry</a></x-slot:action>
        @endif
    </x-page-header>

    @if (! $management)
        <x-alert type="info" title="Need a repair or maintenance service?" class="mt-6">
            Create a Work Order so the concern can be formally screened, assigned, and tracked.
            @can('work-orders.create-request')<a class="ml-1 font-semibold underline" href="{{ route('work-orders.create') }}">Create Work Order</a>@endcan
        </x-alert>
    @else
        <form class="filter-bar mt-6" method="GET" action="{{ route('fmo-inquiries.index') }}">
            <label class="min-w-52 flex-1">Search<input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Requester, subject, category or Work Order"></label>
            <label>Status<select name="status"><option value="">All statuses</option>@foreach(\App\Enums\FmoContactStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ \Illuminate\Support\Str::headline($status->value) }}</option>@endforeach</select></label>
            <label>Category<select name="category"><option value="">All categories</option>@foreach(\App\Enums\FmoContactCategory::cases() as $category)<option value="{{ $category->value }}" @selected(($filters['category'] ?? '') === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
            <label>Date<select name="date"><option value="">Any time</option><option value="7" @selected(($filters['date'] ?? '') === '7')>Last 7 days</option><option value="30" @selected(($filters['date'] ?? '') === '30')>Last 30 days</option><option value="90" @selected(($filters['date'] ?? '') === '90')>Last 90 days</option></select></label>
            <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply</button>
            <a class="btn btn-ghost" href="{{ route('fmo-inquiries.index') }}">Reset</a>
        </form>
    @endif

    <div class="table-wrap mobile-card-table mt-6">
        <table>
            <thead><tr>@if($management)<th>Requester</th>@endif<th>Inquiry</th><th>Category</th><th>Last Activity</th><th>Status</th><th class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse ($threads as $thread)
                    @php($showRoute = $management ? 'fmo-inquiries.show' : 'contact-fmo.show')
                    <tr>
                        @if($management)<td data-label="Requester"><p class="font-semibold text-slate-900">{{ $thread->requester->name }}</p><p class="text-xs text-slate-500">{{ $thread->requester->requesterProfile?->department }}</p></td>@endif
                        <td data-label="Inquiry"><a class="font-semibold text-blue-800 hover:underline" href="{{ route($showRoute, $thread) }}">{{ $thread->subject }}</a>@if($thread->relatedWorkOrder)<p class="mt-1 text-xs text-slate-500">{{ $thread->relatedWorkOrder->work_order_number }}</p>@endif</td>
                        <td data-label="Category">{{ $thread->category->label() }}</td>
                        <td data-label="Last Activity">{{ $thread->updated_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</td>
                        <td data-label="Status"><span class="status-badge {{ $thread->status === \App\Enums\FmoContactStatus::Closed ? 'status-neutral' : ($thread->status === \App\Enums\FmoContactStatus::Responded ? 'status-success' : 'status-info') }}">{{ \Illuminate\Support\Str::headline($thread->status->value) }}</span></td>
                        <td data-label="Action" class="text-right"><a class="btn btn-secondary !min-h-9 !px-3" href="{{ route($showRoute, $thread) }}"><x-icon name="eye" size="size-4" />View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $management ? 6 : 5 }}"><x-empty-state :title="$management ? 'No matching inquiries' : 'No inquiries yet'" :description="$management ? 'No FMO inquiries match the selected filters.' : 'Start an inquiry when you have a general facilities question that is not a repair request.'" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-5">{{ $threads->links() }}</div>
@endsection
