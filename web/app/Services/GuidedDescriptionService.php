<?php

namespace App\Services;

use App\Models\GuidedDescriptionProfile;
use App\Models\WorkOrderCategory;
use Illuminate\Validation\ValidationException;

class GuidedDescriptionService
{
    public function profileForCategory(WorkOrderCategory $category): GuidedDescriptionProfile
    {
        $profile = $category->relationLoaded('guidedProfile')
            ? $category->guidedProfile
            : $category->guidedProfile()->first();

        if ($profile?->is_active) {
            return $profile;
        }

        return GuidedDescriptionProfile::query()->where('code', 'GENERIC')->where('is_active', true)->first()
            ?? new GuidedDescriptionProfile($this->genericProfile());
    }

    /** @param list<string> $actionCodes */
    public function generate(GuidedDescriptionProfile $profile, array $actionCodes, string $outcomeCode, ?string $otherAction = null): string
    {
        $outcomes = collect($profile->outcomes)->keyBy('code');
        if (! $outcomes->has($outcomeCode)) {
            throw ValidationException::withMessages(['guided_actions' => 'One or more guided work choices are invalid.']);
        }

        return $this->actionSentence($profile, $actionCodes, $otherAction).' '.(string) $outcomes[$outcomeCode]['clause'];
    }

    /** @param list<string> $actionCodes */
    public function actionSentence(GuidedDescriptionProfile $profile, array $actionCodes, ?string $otherAction = null): string
    {
        $actions = collect($profile->actions)->keyBy('code');
        $unknown = collect($actionCodes)->reject(fn (string $code): bool => $actions->has($code));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages(['guided_actions' => 'One or more guided work choices are invalid.']);
        }
        if (in_array('OTHER_ACTION', $actionCodes, true) && blank($otherAction)) {
            throw ValidationException::withMessages(['other_action' => 'Please describe the other work performed.']);
        }

        $clauses = collect($actionCodes)->unique()->map(fn (string $code): string => $code === 'OTHER_ACTION'
            ? rtrim(trim((string) $otherAction), '. ')
            : $actions[$code]['clause'])->unique();
        if ($clauses->isEmpty()) {
            throw ValidationException::withMessages(['guided_actions' => 'Select at least one action performed.']);
        }

        $work = $this->naturalJoin($clauses->all());
        $summary = ucfirst($work).'.';

        return preg_replace('/\s+/', ' ', trim($summary)) ?: trim($summary);
    }

    /** @return array<string, mixed> */
    public function genericProfile(): array
    {
        return [
            'code' => 'GENERIC',
            'name' => 'General work',
            'context' => 'WORK_ORDER',
            'actions' => [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected the affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the area', 'clause' => 'cleaned the affected area'],
                ['code' => 'REPAIR_ITEM', 'label' => 'Repaired the item', 'clause' => 'repaired the damaged item'],
                ['code' => 'REPLACE_PART', 'label' => 'Replaced a damaged part', 'clause' => 'replaced the damaged part'],
                ['code' => 'ADJUST_COMPONENT', 'label' => 'Adjusted the component', 'clause' => 'adjusted the affected component'],
                ['code' => 'TEST_WORK', 'label' => 'Tested after the work', 'clause' => 'tested the completed work'],
                ['code' => 'OTHER_ACTION', 'label' => 'Other work performed', 'clause' => 'completed other work'],
            ],
            'outcomes' => $this->standardOutcomes(),
            'sentence_templates' => ['version' => 2],
            'is_active' => true,
        ];
    }

    /** @return list<array{code: string, label: string, description: string, clause: string}> */
    public function standardOutcomes(): array
    {
        return [
            ['code' => 'RESOLVED', 'label' => 'Working Properly', 'description' => 'The reported issue is resolved.', 'clause' => 'The reported issue is now working properly.'],
            ['code' => 'PARTIAL', 'label' => 'More Work Is Needed', 'description' => 'Some work was completed, but additional work is still required.', 'clause' => 'Some work was completed, but additional work is still required.'],
            ['code' => 'WAITING_SUPPLIES', 'label' => 'Waiting for Materials', 'description' => 'Work cannot continue until materials or parts are available.', 'clause' => 'Work will continue when the required materials or parts are available.'],
            ['code' => 'PROBLEM_PERSISTS', 'label' => 'Problem Still Exists', 'description' => 'The work performed did not fully resolve the problem.', 'clause' => 'The problem still exists and requires additional work.'],
            ['code' => 'INVESTIGATION', 'label' => 'Further Inspection Is Needed', 'description' => 'More checking or diagnosis is needed.', 'clause' => 'Further inspection is required before the repair can continue.'],
        ];
    }

    /** @param list<string> $parts */
    private function naturalJoin(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        if (count($parts) === 2) {
            return implode(' and ', $parts);
        }
        $last = array_pop($parts);

        return implode(', ', $parts).', and '.$last;
    }
}
