@php($initialAction = old('staff_action'))

<div id="field-actions" class="mt-6 scroll-mt-24" data-work-action-guide data-initial-action="{{ $initialAction }}">
    <div data-work-action-chooser>
        <div class="max-w-2xl"><p class="detail-kicker">Your active work</p><h3 class="mt-1 text-xl text-slate-900">What would you like to do?</h3><p class="mt-2 text-sm leading-6 text-slate-600">Choose the action that best matches what is happening now.</p></div>
        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            <button type="button" data-work-action-choice="progress" class="group flex min-h-28 w-full items-start gap-4 rounded-xl border border-blue-200 bg-white p-4 text-left transition hover:border-blue-500 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-blue-100 text-blue-800"><x-icon name="wrench" /></span><span><span class="block font-semibold text-slate-900">Add Work Progress</span><span class="mt-1 block text-sm leading-5 text-slate-600">Record what you have done so far.</span></span>
            </button>
            <button type="button" data-work-action-choice="stop" class="group flex min-h-28 w-full items-start gap-4 rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-blue-500 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-700"><x-icon name="clock" /></span><span><span class="block font-semibold text-slate-900">Stop Work for Now</span><span class="mt-1 block text-sm leading-5 text-slate-600">You are stopping now, but more work will continue later.</span></span>
            </button>
            <button type="button" data-work-action-choice="blocked" class="group flex min-h-28 w-full items-start gap-4 rounded-xl border border-amber-200 bg-white p-4 text-left transition hover:border-amber-500 hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-800"><x-icon name="warning" /></span><span><span class="block font-semibold text-slate-900">I Cannot Continue</span><span class="mt-1 block text-sm leading-5 text-slate-600">Something is preventing you from continuing the work.</span></span>
            </button>
            <button type="button" data-work-action-choice="contribution" class="group flex min-h-28 w-full items-start gap-4 rounded-xl border border-indigo-200 bg-white p-4 text-left transition hover:border-indigo-500 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-indigo-100 text-indigo-800"><x-icon name="user" /></span><span><span class="block font-semibold text-slate-900">My Assigned Work Is Finished</span><span class="mt-1 block text-sm leading-5 text-slate-600">Use this if your own part is done but other staff may still be working.</span></span>
            </button>
            @can('work_orders.submit_completion')
                <button type="button" data-work-action-choice="complete" class="group flex min-h-28 w-full items-start gap-4 rounded-xl border border-emerald-200 bg-white p-4 text-left transition hover:border-emerald-500 hover:bg-emerald-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 sm:col-span-2">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-800"><x-icon name="check-circle" /></span><span><span class="block font-semibold text-slate-900">The Whole Job Is Finished</span><span class="mt-1 block text-sm leading-5 text-slate-600">All required work is complete and the Work Order is ready for FMO verification.</span></span>
                </button>
            @endcan
        </div>
    </div>

    <section data-work-action-panel="progress" class="hidden max-w-3xl rounded-xl border border-blue-200 bg-blue-50/40 p-4 sm:p-6">
        <button type="button" data-work-action-back class="mb-5 inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline">← Choose another action</button>
        <h3 class="text-xl text-slate-900">Add Work Progress</h3><p class="mt-2 text-sm text-slate-600">Record what you inspected, repaired, or completed. Your work stays active.</p>
        @php($needsBeforePhotos = config('work_orders.execution.require_before_evidence') && ! $order->updates->where('execution_cycle', $order->execution_cycle)->where('type', \App\Enums\WorkUpdateType::Before)->contains(fn ($update) => $update->attachments->isNotEmpty()))
        @if ($needsBeforePhotos)
            <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.update', [$order, $mySession]) }}" class="mt-6 space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-4">@csrf
                <input type="hidden" name="staff_action" value="before"><input type="hidden" name="type" value="BEFORE"><input type="hidden" name="description" value="Condition recorded before repair.">
                <div><p class="font-semibold text-slate-900">Before Work Photos</p><p class="mt-1 text-sm text-slate-600">Take photos showing the condition before repair.</p></div>
                <x-photo-picker id="before-work-{{ $mySession->id }}" title="Before Work Photos" help="At least one photo is required before completing this type of work." />
                <button class="btn btn-secondary min-h-12 w-full sm:w-auto" type="submit"><x-icon name="camera" />Save Before Work Photos</button>
            </form>
        @endif
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.update', [$order, $mySession]) }}" class="mt-6 space-y-5">@csrf
            <input type="hidden" name="staff_action" value="progress"><input type="hidden" name="type" value="PROGRESS">
            <x-guided-work-description :profile="$guidedProfile" />
            <x-photo-picker id="work-progress-{{ $mySession->id }}" title="Photos of your work" help="Take photos showing what you inspected, repaired, or completed." />
            <button class="btn btn-primary min-h-12 w-full sm:w-auto" type="submit"><x-icon name="check-circle" />Save Work Progress</button>
        </form>
    </section>

    <section data-work-action-panel="stop" class="hidden max-w-3xl rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-6">
        <button type="button" data-work-action-back class="mb-5 inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline">← Choose another action</button>
        <h3 class="text-xl text-slate-900">Stop Work for Now</h3><p class="mt-2 text-sm text-slate-600">Save what you accomplished and what remains before you stop.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-6 space-y-5">@csrf
            <input type="hidden" name="staff_action" value="stop"><input type="hidden" name="outcome" value="CONTINUATION">
            <fieldset><legend class="text-base font-semibold text-slate-900">Why are you stopping for now?</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach (['END_OF_HOURS' => 'End of working hours', 'CONTINUE_TOMORROW' => 'Will continue tomorrow', 'ADDITIONAL_WORK' => 'Additional work is required', 'NEED_MORE_TIME' => 'Need more time', 'WAITING_FOR_STAFF' => 'Waiting for another assigned staff', 'AREA_UNAVAILABLE' => 'Area temporarily unavailable', 'OTHER' => 'Other reason'] as $value => $label)
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 has-checked:border-blue-600 has-checked:bg-blue-50"><input class="size-5" type="radio" name="stop_reason" value="{{ $value }}" required @checked(old('stop_reason') === $value)><span class="text-sm font-medium">{{ $label }}</span></label>
                @endforeach
            </div></fieldset>
            <x-guided-work-description :profile="$guidedProfile" summary-name="summary" summary-label="What did you accomplish before stopping?" action-prompt="What did you accomplish before stopping?" fixed-outcome="PARTIAL" context="stop" />
            <label>What still needs to be done?<textarea name="remaining_work" placeholder="Briefly describe the next work needed.">{{ old('remaining_work') }}</textarea></label>
            <x-photo-picker id="work-stop-{{ $mySession->id }}" title="Photos of the work so far" help="Take photos showing the current condition and where work will continue." />
            <button class="btn btn-secondary min-h-12 w-full sm:w-auto" type="submit"><x-icon name="clock" />Save and Stop for Now</button>
        </form>
    </section>

    <section data-work-action-panel="blocked" class="hidden max-w-3xl rounded-xl border border-amber-200 bg-amber-50/50 p-4 sm:p-6">
        <button type="button" data-work-action-back class="mb-5 inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline">← Choose another action</button>
        <h3 class="text-xl text-slate-900">I Cannot Continue</h3><p class="mt-2 text-sm text-slate-600">Tell the FMO team what is blocking the work.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-6 space-y-5">@csrf
            <input type="hidden" name="staff_action" value="blocked"><input type="hidden" name="outcome" value="PAUSED">
            <fieldset><legend class="text-base font-semibold text-slate-900">What is preventing you from continuing?</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach (['MATERIALS_UNAVAILABLE' => 'Materials are not available', 'REPLACEMENT_PART' => 'A replacement part is needed', 'TOOLS_UNAVAILABLE' => 'Required tools or equipment are unavailable', 'NO_ACCESS' => 'Cannot access the area', 'NEED_STAFF_HELP' => 'Need help from another staff member', 'EXTERNAL_SERVICE' => 'Need an external technician or service', 'FURTHER_INSPECTION' => 'Need further inspection', 'SAFETY' => 'Safety concern', 'OTHER' => 'Other reason'] as $value => $label)
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 has-checked:border-amber-600 has-checked:bg-amber-50"><input class="size-5" type="radio" name="blocker_reason" value="{{ $value }}" required @checked(old('blocker_reason') === $value)><span class="text-sm font-medium">{{ $label }}</span></label>
                @endforeach
            </div></fieldset>
            <label data-material-field class="{{ in_array(old('blocker_reason'), ['MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART'], true) ? '' : 'hidden' }}">What materials are needed?<input name="material_description" value="{{ old('material_description') }}" placeholder="Example: replacement electrical outlet"></label>
            <x-guided-work-description :profile="$guidedProfile" summary-name="summary" summary-label="What did you complete before you were blocked?" action-prompt="What did you complete before you were blocked?" fixed-outcome="PROBLEM_PERSISTS" context="blocked" />
            <x-photo-picker id="work-blocked-{{ $mySession->id }}" title="Photos of the problem" help="Take photos showing the issue or current condition." />
            <button class="btn btn-primary min-h-12 w-full sm:w-auto" type="submit"><x-icon name="warning" />Report What Is Blocking the Work</button>
        </form>
    </section>

    <section data-work-action-panel="contribution" class="hidden max-w-3xl rounded-xl border border-indigo-200 bg-indigo-50/40 p-4 sm:p-6">
        <button type="button" data-work-action-back class="mb-5 inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline">← Choose another action</button>
        <h3 class="text-xl text-slate-900">My Assigned Work Is Finished</h3><p class="mt-2 text-sm text-slate-600">Choose this when your part is complete but other assigned personnel may still have work to do.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-6 space-y-5">@csrf
            <input type="hidden" name="staff_action" value="contribution"><input type="hidden" name="outcome" value="CONTRIBUTION_COMPLETE">
            <x-guided-work-description :profile="$guidedProfile" summary-name="summary" summary-label="What did you complete?" action-prompt="What did you complete?" fixed-outcome="RESOLVED" context="contribution" />
            <x-photo-picker id="work-contribution-{{ $mySession->id }}" title="Photos of your finished work" help="Take photos showing the part of the work you completed." />
            <button class="btn btn-primary min-h-12 w-full sm:w-auto" type="submit"><x-icon name="user" />Save My Finished Work</button>
        </form>
    </section>

    @can('work_orders.submit_completion')
        <section data-work-action-panel="complete" class="hidden max-w-3xl rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 sm:p-6">
            <button type="button" data-work-action-back class="mb-5 inline-flex min-h-10 items-center text-sm font-semibold text-blue-800 hover:underline">← Choose another action</button>
            <h3 class="text-xl text-slate-900">The Whole Job Is Finished</h3><p class="mt-2 text-sm text-slate-600">Choose this only when all required work is complete and the Work Order is ready for verification.</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('work-orders.execution.end', [$order, $mySession]) }}" class="mt-6 space-y-5" data-confirm="Is all required work finished? Submit only if no additional repair or work is required.">@csrf
                <input type="hidden" name="staff_action" value="complete"><input type="hidden" name="outcome" value="WORK_ORDER_COMPLETION_SUBMITTED"><input type="hidden" name="completion_summary"><input type="hidden" name="work_performed_summary">
                <div data-final-builder class="space-y-5">
                    <x-guided-work-description :profile="$guidedProfile" summary-name="summary" summary-label="Completion Summary" action-prompt="What work was completed?" fixed-outcome="RESOLVED" context="complete" />
                    <x-photo-picker id="work-complete-{{ $mySession->id }}" title="Completion Photos" help="Take photos showing the finished work. At least one completion photo is required unless a photo cannot meaningfully show the result." />
                    <label>If a photo cannot show the result <span class="font-normal text-slate-400">(optional)</span><span class="mb-2 mt-1 block text-xs font-normal text-slate-500">Explain why in at least 20 characters.</span><textarea name="non_photo_reason">{{ old('non_photo_reason') }}</textarea></label>
                    <button class="btn btn-success min-h-12 w-full sm:w-auto" type="button" data-final-review-button><x-icon name="check-circle" />Review Finished Work</button>
                </div>
                <div data-final-review class="hidden scroll-mt-24 rounded-xl border border-emerald-200 bg-white p-4 sm:p-5">
                    <p class="detail-kicker !text-emerald-700">Before submitting as finished</p>
                    <h4 class="mt-1 text-lg text-slate-900">Review your completed work</h4>
                    <div class="mt-5 space-y-5"><div><p class="detail-label">Work performed</p><ul data-final-actions class="mt-2 space-y-2"></ul></div><div><p class="detail-label">Result</p><p class="mt-2 text-sm font-medium text-emerald-800">Working properly</p></div><div><p class="detail-label">Completion Summary</p><p data-final-summary class="mt-2 rounded-lg bg-slate-50 p-3 text-sm leading-6 text-slate-700"></p></div><div><p class="detail-label">Completion Photos</p><p data-final-photo-count class="mt-2 text-sm text-slate-700"></p></div></div>
                    <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4"><input class="mt-1 size-5" type="checkbox" data-final-confirm disabled><span><span class="block font-semibold text-slate-900">Is all required work finished?</span><span class="mt-1 block text-sm text-slate-600">Submit only if no additional repair or work is required.</span></span></label>
                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button class="btn btn-secondary min-h-12" type="button" data-final-review-back>Go Back</button><button class="btn btn-success min-h-12" type="submit"><x-icon name="shield-check" />Submit for Verification</button></div>
                </div>
            </form>
        </section>
    @endcan
</div>
