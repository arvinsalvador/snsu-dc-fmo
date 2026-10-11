@props([
    'name' => 'evidence',
    'id',
    'title' => 'Add Evidence Photos',
    'help' => 'Photos are attached to this record after you submit the form.',
    'allowVideo' => false,
])

<div data-photo-picker data-max-files="{{ config('work_orders.attachments.max_count') }}" data-max-bytes="{{ config('work_orders.attachments.max_size_kb') * 1024 }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
    <div>
        <p class="text-sm font-semibold text-slate-900">{{ $title }}</p>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $help }}</p>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <label for="{{ $id }}-camera" class="btn btn-primary min-h-11 cursor-pointer"><x-icon name="camera" />Take Photo</label>
        <input id="{{ $id }}-camera" data-photo-input data-photo-camera class="sr-only" type="file" name="{{ $name }}[]" accept="image/*" capture="environment">
        <label for="{{ $id }}-choose" class="btn btn-secondary min-h-11 cursor-pointer"><x-icon name="upload" />Choose {{ $allowVideo ? 'Files' : 'Photos' }}</label>
        <input id="{{ $id }}-choose" data-photo-input class="sr-only" type="file" name="{{ $name }}[]" multiple accept="{{ $allowVideo ? 'image/jpeg,image/png,image/webp,video/mp4' : 'image/jpeg,image/png,image/webp' }}">
    </div>
    <p data-photo-error class="mt-3 hidden text-sm font-medium text-rose-700" role="alert"></p>
    <div data-photo-preview class="mt-4 hidden grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" aria-label="Selected files"></div>
    <p data-photo-empty class="mt-3 text-xs text-slate-500">No photos selected.</p>
</div>
