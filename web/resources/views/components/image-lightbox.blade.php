<div data-image-lightbox class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="image-lightbox-title">
    <div data-lightbox-backdrop class="absolute inset-0 bg-slate-950/90"></div>
    <div class="relative flex h-full flex-col p-3 sm:p-6">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 text-white">
            <div class="min-w-0">
                <p id="image-lightbox-title" data-lightbox-label class="truncate font-semibold">Evidence photo</p>
                <p data-lightbox-meta class="truncate text-xs text-slate-300"></p>
            </div>
            <button data-lightbox-close type="button" class="grid size-11 shrink-0 place-items-center rounded-full bg-white/10 text-2xl hover:bg-white/20" aria-label="Close image viewer">×</button>
        </div>
        <div data-lightbox-swipe class="relative mx-auto mt-3 flex min-h-0 w-full max-w-6xl flex-1 items-center justify-center overflow-hidden rounded-xl bg-black/30">
            <button data-lightbox-previous type="button" class="absolute left-2 z-10 grid size-12 place-items-center rounded-full bg-black/55 text-2xl text-white hover:bg-black/75 sm:left-4" aria-label="Previous photo">‹</button>
            <img data-lightbox-image class="max-h-full max-w-full object-contain" alt="">
            <button data-lightbox-next type="button" class="absolute right-2 z-10 grid size-12 place-items-center rounded-full bg-black/55 text-2xl text-white hover:bg-black/75 sm:right-4" aria-label="Next photo">›</button>
        </div>
        <div class="mx-auto mt-3 flex w-full max-w-6xl items-center justify-between text-sm text-white">
            <span data-lightbox-counter>1 of 1</span>
            <a data-lightbox-open class="font-semibold text-blue-200 hover:text-white" href="#" target="_blank" rel="noopener">Open full image</a>
        </div>
    </div>
</div>
