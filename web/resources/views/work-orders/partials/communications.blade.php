@php
    $fmoSuggestions = [
        'We have received your concern and will inspect the area.',
        'The reported issue has been inspected.',
        'Additional materials are required before work can continue.',
        'Work will continue on the next working day.',
        'Please avoid using the affected equipment until further notice.',
        'The repair has been completed. Please check the affected area.',
        'We need additional information about the reported concern.',
        'Your concern has been forwarded to FMO management for review.',
    ];
    $requesterSuggestions = [
        'May I ask for an update on this Work Order?',
        'I would like to provide additional information.',
        'The reported problem is still present.',
        'The condition has become worse.',
        'Thank you. The issue appears resolved.',
    ];
    $messageSuggestions = $order->requester_id === $user->id ? $requesterSuggestions : $fmoSuggestions;
@endphp

@if ($canViewMessages || $canViewInternalNotes)
    <section id="communications" class="section-card mt-6 scroll-mt-24">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
            <div>
                <p class="detail-kicker">Communication</p>
                <h2 class="mt-1 text-xl">Messages &amp; Coordination</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Work Order messages keep requester and authorized FMO coordination attached to this request. Messages do not change its workflow status.</p>
            </div>
            @if ($messageUnreadCount > 0)
                <span class="inline-flex w-fit rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">{{ $messageUnreadCount }} unread</span>
            @endif
        </div>

        <nav class="mt-5 flex gap-2 border-b border-slate-200" aria-label="Communication sections">
            @if ($canViewMessages)
                <a class="border-b-2 px-3 py-3 text-sm font-semibold {{ $communicationTab === 'messages' ? 'border-blue-700 text-blue-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'messages']).'#communications' }}">
                    Messages
                    @if ($messageUnreadCount > 0)
                        ({{ $messageUnreadCount }})
                    @endif
                </a>
            @endif
            @if ($canViewInternalNotes)
                <a class="border-b-2 px-3 py-3 text-sm font-semibold {{ $communicationTab === 'notes' ? 'border-amber-600 text-amber-800' : 'border-transparent text-slate-500 hover:text-slate-800' }}" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'notes']).'#communications' }}">Internal Notes</a>
            @endif
        </nav>

        @if ($communicationTab === 'messages' && $canViewMessages)
            <div class="mx-auto mt-5 max-w-4xl">
                @if ($messages->hasMorePages())
                    <div class="mb-4 text-center"><a class="btn btn-ghost" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'messages', 'messages_page' => $messages->currentPage() + 1]).'#communications' }}">Load earlier messages</a></div>
                @endif
                <div class="space-y-3">
                    @forelse ($messages as $message)
                        <x-communication-message
                            :message="$message"
                            :sender="$message->sender"
                            :role="$message->sender_user_id === $order->requester_id ? 'Requester' : ($message->sender->getRoleNames()->first() ?? 'FMO Staff')"
                            :attachments="$message->attachments"
                            :group="'work-order-message-'.$message->id"
                            :own="$message->sender_user_id === $user->id"
                        />
                    @empty
                        <x-empty-state title="No messages yet" description="Use this conversation for questions and coordination about this Work Order." />
                    @endforelse
                </div>
                @if ($messages->currentPage() > 1)
                    <div class="mt-4 text-center"><a class="btn btn-ghost" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'messages', 'messages_page' => $messages->currentPage() - 1]).'#communications' }}">Load newer messages</a></div>
                @endif

                <div class="mt-6">
                    @if ($canSendMessage)
                        <x-communication-composer
                            :action="route('work-orders.messages.store', $order)"
                            :id="'work-order-message-'.$order->id"
                            :suggestions="$messageSuggestions"
                            title="Send a Work Order Message"
                            placeholder="Share a question or coordination update…"
                        />
                    @else
                        <x-alert type="info" title="Conversation is read-only">Messages remain available as part of the Work Order history, but new messages cannot be sent after the Work Order is closed.</x-alert>
                    @endif
                </div>
            </div>
        @elseif ($communicationTab === 'notes' && $canViewInternalNotes)
            <div class="mx-auto mt-5 max-w-4xl">
                <x-alert type="warning" title="FMO Internal">Only authorized FMO personnel can see these notes. They are separate from requester-visible messages and formal work updates.</x-alert>
                @if ($internalNotes->hasMorePages())
                    <div class="my-4 text-center"><a class="btn btn-ghost" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'notes', 'notes_page' => $internalNotes->currentPage() + 1]).'#communications' }}">Load earlier notes</a></div>
                @endif
                <div class="mt-4 space-y-3">
                    @forelse ($internalNotes as $note)
                        <x-communication-message
                            :message="$note"
                            :sender="$note->author"
                            :role="'FMO Internal'"
                            :attachments="$note->attachments"
                            :group="'internal-note-'.$note->id"
                            :own="$note->author_user_id === $user->id"
                            internal
                        />
                    @empty
                        <x-empty-state title="No internal notes yet" description="Authorized FMO personnel can add private coordination notes here." />
                    @endforelse
                </div>
                @if ($internalNotes->currentPage() > 1)
                    <div class="mt-4 text-center"><a class="btn btn-ghost" href="{{ route('work-orders.show', ['workOrder' => $order, 'communication' => 'notes', 'notes_page' => $internalNotes->currentPage() - 1]).'#communications' }}">Load newer notes</a></div>
                @endif
                <div class="mt-6">
                    @if ($canCreateInternalNote)
                        <x-communication-composer
                            :action="route('work-orders.internal-notes.store', $order)"
                            :id="'internal-note-'.$order->id"
                            title="Add Internal Note"
                            placeholder="Add private coordination or instruction for authorized FMO personnel…"
                            internal
                        />
                    @else
                        <x-alert type="info" title="Internal notes are read-only">This closed Work Order keeps its internal coordination history, but no new notes can be added.</x-alert>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endif
