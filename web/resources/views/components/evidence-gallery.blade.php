@props(['title', 'attachments', 'group', 'description' => null])

@php
    $items = $attachments->values();
    $images = $items->filter->isImage()->values();
    $otherFiles = $items->reject->isImage()->values();
@endphp

<section class="evidence-group" aria-labelledby="{{ $group }}-title">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h3 id="{{ $group }}-title" class="text-base font-semibold text-slate-900">{{ $title }}</h3>
            @if ($description)<p class="mt-1 text-sm text-slate-500">{{ $description }}</p>@endif
        </div>
        @if ($items->isNotEmpty())<p class="text-xs font-medium text-slate-500">{{ $items->count() }} {{ \Illuminate\Support\Str::plural('file', $items->count()) }}</p>@endif
    </div>

    @if ($images->isNotEmpty())
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4" data-evidence-gallery="{{ $group }}">
            @foreach ($images as $index => $attachment)
                @php($label = \Illuminate\Support\Str::of($attachment->evidence_type ?: $attachment->purpose)->replace('_', ' ')->title())
                <button
                    type="button"
                    class="group relative aspect-[4/3] overflow-hidden rounded-xl border border-slate-200 bg-slate-100 text-left shadow-xs focus:ring-3 focus:ring-blue-200 {{ $index >= 8 ? 'hidden' : '' }}"
                    data-evidence-item
                    data-gallery="{{ $group }}"
                    data-full="{{ route('work-orders.attachments.show', [$attachment->work_order_id, $attachment]) }}"
                    data-label="{{ $label }}"
                    data-meta="{{ $attachment->uploader?->name }} · {{ $attachment->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}"
                    @if ($index >= 8) data-gallery-extra @endif
                    aria-label="Preview {{ $label }}: {{ $attachment->original_filename }}"
                >
                    <img class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]" src="{{ route('work-orders.attachments.thumbnail', [$attachment->work_order_id, $attachment]) }}" alt="{{ $label }} evidence" loading="lazy" width="{{ $attachment->thumbnail_width ?: 480 }}" height="{{ $attachment->thumbnail_height ?: 360 }}">
                    <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/85 to-transparent px-3 pb-2 pt-8 text-xs font-semibold text-white">{{ $label }}</span>
                </button>
            @endforeach
        </div>
        @if ($images->count() > 8)
            <button type="button" class="btn btn-secondary mt-3" data-gallery-expand="{{ $group }}">View all {{ $images->count() }} photos</button>
        @endif
    @endif

    @if ($otherFiles->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($otherFiles as $attachment)
                <a class="btn btn-secondary" href="{{ route('work-orders.attachments.show', [$attachment->work_order_id, $attachment]) }}" target="_blank" rel="noopener">View {{ $attachment->original_filename }}</a>
                <a class="btn btn-ghost" href="{{ route('work-orders.attachments.download', [$attachment->work_order_id, $attachment]) }}">Download</a>
            @endforeach
        </div>
    @endif

    @if ($items->isEmpty())
        <p class="mt-3 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">No evidence added yet.</p>
    @endif
</section>
