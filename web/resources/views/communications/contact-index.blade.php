@extends('layouts.app')
@section('title', $management ? 'FMO Inquiries' : 'Contact FMO')
@section('content')
    @php
        $indexRoute = $management ? 'fmo-inquiries.index' : 'contact-fmo.index';
        $showRoute = $management ? 'fmo-inquiries.show' : 'contact-fmo.show';
        $tabs = ['open' => 'Open', 'responded' => 'Responded', 'closed' => 'Closed', 'all' => 'All'];
        $query = array_filter($filters, fn ($value) => $value !== '' && $value !== null);
    @endphp
    <x-page-header
        :title="$management ? 'FMO Inquiries' : 'Contact FMO'"
        :description="$management ? 'Review and respond to facilities inquiries from authorized users.' : 'Send general facilities questions or inquiries to the Facilities Management Office.'"
    >
        @if (! $management)
            <x-slot:action>
                <a class="btn btn-primary" href="{{ route('contact-fmo.create') }}">
                    <x-icon name="plus" />
                    New Inquiry
                </a>
            </x-slot:action>
        @endif
    </x-page-header>

    <section class="mt-6 grid gap-4 sm:grid-cols-3" aria-label="Inquiry summary">
        <x-stat-card label="Open" :value="$statusCounts['open']" icon="mail" />
        <x-stat-card label="Responded" :value="$statusCounts['responded']" icon="check-circle" />
        <x-stat-card label="Closed" :value="$statusCounts['closed']" icon="lock" />
    </section>

    @if (! $management)
        <x-alert type="info" title="Need a repair, maintenance, or service request?" class="mt-6">
            Create a Work Order so the concern can be formally screened, assigned, and tracked.
            @can('work_orders.create')<a class="ml-1 font-semibold underline" href="{{ route('work-orders.create') }}">Create Work Order</a>@endcan
        </x-alert>
    @endif

    <nav class="mt-6 overflow-x-auto border-b border-slate-200" aria-label="Inquiry status"><div class="flex min-w-max gap-1">
        @foreach($tabs as $value => $label)
            <a class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold {{ $filters['status'] === $value ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' }}" href="{{ route($indexRoute, [...$query, 'status' => $value]) }}" @if($filters['status'] === $value) aria-current="page" @endif>{{ $label }}<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $statusCounts[$value] }}</span></a>
        @endforeach
    </div></nav>

    <form class="section-card mt-5" method="GET" action="{{ route($indexRoute) }}">
        <input type="hidden" name="status" value="{{ $filters['status'] }}">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-12">
            <label class="md:col-span-2 xl:col-span-4">Search<input name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="{{ $management ? 'Requester, subject, message or Work Order...' : 'Subject, message or Work Order...' }}"></label>
            <label class="xl:col-span-3">Category<select name="category"><option value="">All categories</option>@foreach(\App\Enums\FmoContactCategory::cases() as $category)<option value="{{ $category->value }}" @selected($filters['category'] === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
            <label class="xl:col-span-2">Created<select name="date"><option value="">Any time</option><option value="7" @selected($filters['date'] === '7')>Last 7 days</option><option value="30" @selected($filters['date'] === '30')>Last 30 days</option><option value="90" @selected($filters['date'] === '90')>Last 90 days</option></select></label>
            <label class="xl:col-span-2">Sort<select name="sort"><option value="activity_desc" @selected($filters['sort'] === 'activity_desc')>Newest Activity</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest Created</option><option value="created_desc" @selected($filters['sort'] === 'created_desc')>Newest Created</option></select></label>
            <label class="xl:col-span-1">Per Page<select name="per_page">@foreach([20, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end"><a class="btn btn-secondary" href="{{ route($indexRoute, $filters['status'] === 'all' ? [] : ['status' => $filters['status']]) }}">Clear Filters</a><button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply Filters</button></div>
    </form>

    @if($threads->isEmpty())
        <div class="mt-6"><x-empty-state :title="$hasFilters ? 'No matching records' : ($management ? 'No inquiries in this queue' : 'No inquiries yet')" :description="$hasFilters ? 'Try changing your search or filters.' : ($management ? 'New requester inquiries will appear here.' : 'Start an inquiry when you have a general facilities question that is not a repair request.')" icon="mail">@if($hasFilters)<a class="btn btn-secondary mt-4" href="{{ route($indexRoute) }}">Clear Filters</a>@endif</x-empty-state></div>
    @else
        <div class="table-wrap mobile-card-table mt-6"><table><thead><tr>@if($management)<th>Requester</th>@endif<th>Inquiry</th><th>Category</th><th>Last Activity</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
            @foreach($threads as $thread)
                <tr>
                    @if($management)<td data-label="Requester"><p class="font-semibold text-slate-900">{{ $thread->requester->name }}</p><p class="text-xs text-slate-500">{{ $thread->requester->requesterProfile?->department ?: $thread->requester->email }}</p></td>@endif
                    <td data-label="Inquiry"><a class="font-semibold text-blue-800 hover:underline" href="{{ route($showRoute, $thread) }}">{{ $thread->subject }}</a>@if($thread->relatedWorkOrder)<p class="mt-1 text-xs text-slate-500">{{ $thread->relatedWorkOrder->work_order_number }}</p>@endif @if($thread->messages->first()?->body)<p class="mt-1 line-clamp-1 max-w-sm text-xs text-slate-500">{{ $thread->messages->first()->body }}</p>@endif</td>
                    <td data-label="Category">{{ $thread->category->label() }}</td>
                    <td data-label="Last Activity"><span class="whitespace-nowrap">{{ $thread->updated_at?->timezone('Asia/Manila')->format('M j, Y') }}</span><span class="mt-1 block text-xs text-slate-500">{{ $thread->updated_at?->timezone('Asia/Manila')->format('g:i A') }}</span></td>
                    <td data-label="Status"><span class="status-badge {{ $thread->status === \App\Enums\FmoContactStatus::Closed ? 'status-neutral' : ($thread->status === \App\Enums\FmoContactStatus::Responded ? 'status-success' : 'status-open') }}">● {{ str($thread->status->value)->headline() }}</span></td>
                    <td data-label="Action" class="text-right"><a class="btn btn-secondary w-full !min-h-9 !px-3 sm:w-auto" href="{{ route($showRoute, $thread) }}"><x-icon name="eye" size="size-4" />View Conversation</a></td>
                </tr>
            @endforeach
        </tbody></table></div>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-500">Showing {{ $threads->firstItem() }}–{{ $threads->lastItem() }} of {{ $threads->total() }} inquiries</p>{{ $threads->onEachSide(1)->links() }}</div>
    @endif
@endsection
