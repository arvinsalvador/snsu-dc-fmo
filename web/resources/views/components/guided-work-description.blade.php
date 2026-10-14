@props([
    'profile',
    'summaryName' => 'description',
    'summaryLabel' => 'Work Summary',
    'actionPrompt' => 'What did you do?',
    'outcomePrompt' => 'What happened after the work?',
    'fixedOutcome' => null,
    'context' => 'progress',
    'required' => true,
])

<div class="space-y-5" data-guided-description
     data-actions='@json($profile->actions)' data-outcomes='@json($profile->outcomes)' data-guided-context="{{ $context }}">
    <div>
        <p class="text-base font-semibold text-slate-900">{{ $actionPrompt }}</p>
        <p class="mt-1 text-sm text-slate-500">Select everything that applies.</p>
    </div>
    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($profile->actions as $action)
            <label class="flex min-h-12 cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                <input class="mt-1 size-5 shrink-0" type="checkbox" name="guided_actions[]" value="{{ $action['code'] }}" @checked(in_array($action['code'], old('guided_actions', []), true))>
                <span class="text-sm font-medium leading-6 text-slate-800">{{ $action['label'] }}</span>
            </label>
        @endforeach
    </div>

    <label data-other-action-field class="hidden">Describe the other work performed <span class="text-rose-600">*</span>
        <input name="other_action" value="{{ old('other_action') }}" placeholder="Briefly describe what you did.">
    </label>

    @if ($fixedOutcome)
        <input type="hidden" name="guided_outcome" value="{{ $fixedOutcome }}">
    @else
        <fieldset>
            <legend class="text-base font-semibold text-slate-900">{{ $outcomePrompt }}</legend>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach ($profile->outcomes as $outcome)
                    <label class="flex min-h-14 cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-blue-300 has-checked:border-blue-600 has-checked:bg-blue-50">
                        <input class="mt-1 size-5 shrink-0" type="radio" name="guided_outcome" value="{{ $outcome['code'] }}" @checked(old('guided_outcome') === $outcome['code'])>
                        <span><span class="block text-sm font-semibold text-slate-900">{{ $outcome['label'] }}</span>@if($outcome['description'] ?? null)<span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ $outcome['description'] }}</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif

    <div class="rounded-xl border border-blue-200 bg-blue-50/70 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div><p class="font-semibold text-slate-900">{{ $summaryLabel }}</p><p class="mt-1 text-xs text-slate-600">Generated from your choices. You can use it as written or edit it.</p></div>
            <div class="flex gap-2"><button class="btn btn-secondary !min-h-9 !px-3" type="button" data-guided-use>Use this summary</button><button class="btn btn-secondary !min-h-9 !px-3" type="button" data-guided-edit>Edit summary</button></div>
        </div>
        <textarea class="mt-3 bg-white" name="{{ $summaryName }}" data-guided-summary @required($required) placeholder="Select what you did and what happened to create a summary.">{{ old($summaryName) }}</textarea>
    </div>

    <label>Additional Remarks <span class="font-normal text-slate-400">(optional)</span>
        <span class="mb-2 mt-1 block text-xs font-normal leading-5 text-slate-500">Add anything important that is not included above.</span>
        <textarea name="additional_remarks" placeholder="Add any helpful details.">{{ old('additional_remarks') }}</textarea>
    </label>
</div>
