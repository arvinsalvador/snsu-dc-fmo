<div data-confirm-dialog class="fixed inset-0 z-[60] hidden" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-message">
    <div data-confirm-cancel class="absolute inset-0 bg-slate-950/60 backdrop-blur-[1px]"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start gap-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-800"><x-icon name="warning" /></span>
                <div>
                    <h2 id="confirm-dialog-title" class="text-lg">Confirm action</h2>
                    <p id="confirm-dialog-message" data-confirm-message class="mt-2 text-sm leading-6 text-slate-600">Please confirm that you want to continue.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button data-confirm-cancel class="btn btn-secondary" type="button">Cancel</button>
                <button data-confirm-submit class="btn btn-danger" type="button">Confirm</button>
            </div>
        </div>
    </div>
</div>
