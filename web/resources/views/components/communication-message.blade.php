@props([
    'message',
    'sender',
    'role',
    'attachments',
    'group',
    'own' => false,
    'internal' => false,
])

<article class="rounded-xl border p-4 sm:p-5 {{ $internal ? 'border-amber-200 bg-amber-50/60' : ($own ? 'border-blue-200 bg-blue-50/60' : 'border-slate-200 bg-white') }}">
    <header class="flex flex-wrap items-start justify-between gap-2">
        <div class="flex min-w-0 items-center gap-3">
            <x-avatar :name="$sender->name" size="sm" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ $sender->name }}</p>
                <p class="text-xs text-slate-500">{{ $internal ? 'Internal Note' : $role }}</p>
            </div>
        </div>
        <time class="text-xs text-slate-500" datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</time>
    </header>

    @if ($message->body)
        <p class="mt-4 whitespace-pre-line break-words text-sm leading-6 text-slate-800">{{ $message->body }}</p>
    @endif

    @if ($attachments->isNotEmpty())
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" data-evidence-gallery="{{ $group }}">
            @foreach ($attachments as $attachment)
                <button
                    type="button"
                    class="group relative aspect-[4/3] overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left"
                    data-evidence-item
                    data-gallery="{{ $group }}"
                    data-full="{{ route('communication-attachments.show', $attachment) }}"
                    data-label="Communication photo"
                    data-meta="{{ $attachment->uploader?->name }} · {{ $attachment->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}"
                    aria-label="Preview photo: {{ $attachment->original_filename }}"
                >
                    <img class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]" src="{{ route('communication-attachments.show', [$attachment, 'thumbnail']) }}" alt="Attached communication photo" loading="lazy" width="{{ $attachment->thumbnail_width ?: 480 }}" height="{{ $attachment->thumbnail_height ?: 360 }}">
                </button>
            @endforeach
        </div>
    @endif
</article>
