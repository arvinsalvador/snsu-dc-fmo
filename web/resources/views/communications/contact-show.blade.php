@extends('layouts.app')
@section('title', $thread->subject)
@section('content')
    @php
        $backRoute = $management ? route('fmo-inquiries.index') : route('contact-fmo.index');
        $replyRoute = $management ? route('fmo-inquiries.reply', $thread) : route('contact-fmo.reply', $thread);
        $suggestions = $management ? [
            'We have received your inquiry and are reviewing it.',
            'Please create a Work Order so this repair request can be formally processed.',
            'We need additional information before we can respond.',
            'Your inquiry has been forwarded to the appropriate FMO personnel.',
        ] : [
            'May I ask for an update on this inquiry?',
            'I would like to provide additional information.',
            'Thank you for your response.',
        ];
    @endphp
    <a class="inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline" href="{{ $backRoute }}">← Back to {{ $management ? 'FMO Inquiries' : 'Contact FMO' }}</a>

    <header class="mt-4 border-b border-slate-200 pb-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div><p class="detail-kicker">{{ $thread->category->label() }}</p><h1 class="mt-2 text-balance">{{ $thread->subject }}</h1><p class="mt-2 text-sm text-slate-600">From {{ $thread->requester->name }} · Opened {{ $thread->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p></div>
            <span class="status-badge {{ $thread->status === \App\Enums\FmoContactStatus::Closed ? 'status-neutral' : ($thread->status === \App\Enums\FmoContactStatus::Responded ? 'status-success' : 'status-info') }}">{{ \Illuminate\Support\Str::headline($thread->status->value) }}</span>
        </div>
        @if($thread->relatedWorkOrder)<p class="mt-4 text-sm text-slate-600">Related Work Order: <a class="font-semibold text-blue-800 hover:underline" href="{{ route('work-orders.show', $thread->relatedWorkOrder) }}">{{ $thread->relatedWorkOrder->work_order_number }} — {{ $thread->relatedWorkOrder->subject }}</a></p>@endif
    </header>

    <div class="mx-auto mt-6 max-w-4xl">
        @if($management && $thread->status !== \App\Enums\FmoContactStatus::Closed)
            <div class="mb-5 flex justify-end"><form method="POST" action="{{ route('fmo-inquiries.close', $thread) }}" data-confirm="Close this inquiry? Its history will remain readable, but no new replies can be sent.">@csrf<button class="btn btn-secondary" type="submit">Close Inquiry</button></form></div>
        @endif
        @if($messages->hasMorePages())<div class="mb-4 text-center"><a class="btn btn-ghost" href="{{ request()->url() }}?messages_page={{ $messages->currentPage() + 1 }}">Load earlier messages</a></div>@endif
        <div class="space-y-3">
            @foreach($messages as $message)
                <x-communication-message
                    :message="$message"
                    :sender="$message->sender"
                    :role="$message->sender_user_id === $thread->requester_user_id ? 'Requester' : ($message->sender->getRoleNames()->first() ?? 'FMO')"
                    :attachments="$message->attachments"
                    :group="'fmo-inquiry-message-'.$message->id"
                    :own="$message->sender_user_id === auth()->id()"
                />
            @endforeach
        </div>
        @if($messages->currentPage() > 1)<div class="mt-4 text-center"><a class="btn btn-ghost" href="{{ request()->url() }}?messages_page={{ $messages->currentPage() - 1 }}">Load newer messages</a></div>@endif

        <div class="mt-6">
            @if($canReply)
                <x-communication-composer :action="$replyRoute" :id="'inquiry-'.$thread->id" :suggestions="$suggestions" title="Reply to Inquiry" placeholder="Write a reply…" />
            @else
                <x-alert type="info" title="Inquiry closed">This conversation remains available as history, but it is now read-only.</x-alert>
            @endif
        </div>
    </div>
    <x-image-lightbox />
@endsection
