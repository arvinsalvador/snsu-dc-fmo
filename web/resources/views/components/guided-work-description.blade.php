@props(['profile', 'summaryName' => 'description', 'summaryLabel' => 'Generated work summary', 'required' => true])

<div class="space-y-4 rounded-xl border border-blue-200 bg-white p-4" data-guided-description
     data-actions='@json($profile->actions)' data-outcomes='@json($profile->outcomes)'>
    <div><p class="text-sm font-semibold text-slate-900">What did you do?</p><p class="mt-1 text-xs text-slate-500">Select every action that applies. Your choices remain stored with the readable summary.</p></div>
    <div class="grid gap-2 sm:grid-cols-2">
        @foreach ($profile->actions as $action)
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-checked:border-blue-600 has-checked:bg-blue-50">
                <input class="mt-1" type="checkbox" name="guided_actions[]" value="{{ $action['code'] }}" @checked(in_array($action['code'], old('guided_actions', []), true))>
                <span class="text-sm font-medium text-slate-800">{{ $action['label'] }}</span>
            </label>
        @endforeach
    </div>
    <fieldset><legend class="text-sm font-semibold text-slate-900">What was the result?</legend><div class="mt-2 grid gap-2 sm:grid-cols-2">
        @foreach ($profile->outcomes as $outcome)
            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 has-checked:border-blue-600 has-checked:bg-blue-50"><input type="radio" name="guided_outcome" value="{{ $outcome['code'] }}" @checked(old('guided_outcome') === $outcome['code'])><span class="text-sm font-medium">{{ $outcome['label'] }}</span></label>
        @endforeach
    </div></fieldset>
    <label>{{ $summaryLabel }} <span class="font-normal text-slate-400">(editable)</span><textarea name="{{ $summaryName }}" data-guided-summary @required($required) placeholder="Select actions and a result, then edit the generated summary if needed.">{{ old($summaryName) }}</textarea></label>
    <label>Additional remarks <span class="font-normal text-slate-400">(optional)</span><textarea name="additional_remarks" placeholder="Add context not covered by the guided choices.">{{ old('additional_remarks') }}</textarea></label>
</div>
