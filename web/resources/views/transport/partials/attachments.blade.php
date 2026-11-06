@props(['title', 'attachments', 'empty' => 'No files uploaded.'])
<div>
    <h3 class="text-sm font-semibold text-slate-800">{{ $title }}</h3>
    @if ($attachments->isEmpty())
        <p class="mt-1 text-sm text-slate-500">{{ $empty }}</p>
    @else
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach ($attachments as $attachment)
                <li class="flex flex-wrap items-center gap-2">
                    @if ($attachment->thumbnail_path)
                        <a href="{{ route('routine-evidence.show', $attachment) }}" target="_blank" rel="noopener"><img class="h-12 w-12 rounded-lg border border-slate-200 object-cover" src="{{ route('routine-evidence.show', [$attachment, 'thumbnail']) }}" alt="{{ $attachment->original_filename }}" loading="lazy"></a>
                    @endif
                    <a class="font-medium text-blue-800 underline" href="{{ route('routine-evidence.show', $attachment) }}" target="_blank" rel="noopener">{{ $attachment->original_filename }}</a>
                    <span class="text-xs text-slate-500">{{ $attachment->uploader?->name }} · {{ $attachment->created_at->format('M j, Y g:i A') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
