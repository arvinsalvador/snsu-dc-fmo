@props([
    'action',
    'id',
    'suggestions' => [],
    'title' => 'Send a Message',
    'placeholder' => 'Write your message…',
    'internal' => false,
])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="rounded-xl border p-4 sm:p-5 {{ $internal ? 'border-amber-200 bg-amber-50/50' : 'border-slate-200 bg-slate-50' }}" data-communication-composer>
    @csrf
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
        @if (count($suggestions))
            <div class="mt-3 flex gap-2 overflow-x-auto pb-2" aria-label="Suggested replies">
                @foreach ($suggestions as $suggestion)
                    <button class="shrink-0 rounded-full border border-slate-300 bg-white px-3 py-2 text-left text-xs font-medium text-slate-700 hover:border-blue-300 hover:bg-blue-50" type="button" data-communication-suggestion="{{ $suggestion }}">{{ $suggestion }}</button>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-slate-500">Selecting a suggestion fills the editor. You can change it before sending.</p>
        @endif
    </div>
    <label class="mt-4 block text-sm font-semibold text-slate-800" for="{{ $id }}-message">
        Message
        <textarea id="{{ $id }}-message" name="message" rows="4" maxlength="{{ config('communications.message_max_length') }}" placeholder="{{ $placeholder }}" data-communication-textarea>{{ old('message') }}</textarea>
    </label>
    <div class="mt-4">
        <x-photo-picker
            name="attachments"
            :id="$id.'-photos'"
            title="Attach Photos (Optional)"
            help="Photos provide communication context and are not formal maintenance evidence."
            :max-files="config('communications.attachments.max_count')"
            :max-size-kb="config('communications.attachments.max_size_kb')"
        />
    </div>
    <div class="mt-4 flex justify-end">
        <button class="btn btn-primary w-full sm:w-auto" type="submit" data-loading-text="Sending…"><x-icon name="mail" />Send Message</button>
    </div>
</form>
