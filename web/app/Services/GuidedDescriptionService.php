<?php

namespace App\Services;

use App\Models\GuidedDescriptionProfile;
use Illuminate\Validation\ValidationException;

class GuidedDescriptionService
{
    /** @param list<string> $actionCodes */
    public function generate(GuidedDescriptionProfile $profile, array $actionCodes, string $outcomeCode): string
    {
        $actions = collect($profile->actions)->keyBy('code');
        $outcomes = collect($profile->outcomes)->keyBy('code');
        $unknown = collect($actionCodes)->reject(fn (string $code): bool => $actions->has($code));
        if ($unknown->isNotEmpty() || ! $outcomes->has($outcomeCode)) {
            throw ValidationException::withMessages(['guided_actions' => 'One or more guided work choices are invalid.']);
        }

        $clauses = collect($actionCodes)->unique()->map(fn (string $code): string => $actions[$code]['clause']);
        if ($clauses->isEmpty()) {
            throw ValidationException::withMessages(['guided_actions' => 'Select at least one action performed.']);
        }

        $work = $this->naturalJoin($clauses->all());
        $summary = ucfirst($work).'. '.(string) $outcomes[$outcomeCode]['clause'];

        return preg_replace('/\s+/', ' ', trim($summary)) ?: trim($summary);
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
