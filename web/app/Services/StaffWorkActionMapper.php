<?php

namespace App\Services;

use App\Enums\WorkSessionOutcome;
use Illuminate\Validation\ValidationException;

class StaffWorkActionMapper
{
    /** @param array<string, mixed> $data */
    public function outcome(array $data): WorkSessionOutcome
    {
        return match ($data['staff_action'] ?? null) {
            'stop' => $this->stopOutcome((string) ($data['stop_reason'] ?? '')),
            'blocked' => $this->blockerOutcome((string) ($data['blocker_reason'] ?? '')),
            'contribution' => WorkSessionOutcome::ContributionComplete,
            'complete' => WorkSessionOutcome::WorkOrderCompletionSubmitted,
            default => isset($data['outcome'])
                ? WorkSessionOutcome::from($data['outcome'])
                : throw ValidationException::withMessages(['staff_action' => 'Please choose what you would like to do.']),
        };
    }

    /** @param array<string, mixed> $data */
    public function generatedSummary(string $actionSentence, array $data): string
    {
        return match ($data['staff_action'] ?? null) {
            'stop' => $actionSentence.' '.(($data['stop_reason'] ?? null) === 'AREA_UNAVAILABLE'
                ? 'Work is paused because the area is temporarily unavailable.'
                : 'Additional work remains and will continue during the next work period.'),
            'blocked' => $actionSentence.' '.$this->blockerSentence($data),
            'contribution' => $actionSentence.' My assigned work is complete.',
            'complete' => $actionSentence.' All required work is complete and ready for FMO verification.',
            default => $actionSentence,
        };
    }

    private function stopOutcome(string $reason): WorkSessionOutcome
    {
        if (! in_array($reason, ['END_OF_HOURS', 'CONTINUE_TOMORROW', 'ADDITIONAL_WORK', 'NEED_MORE_TIME', 'WAITING_FOR_STAFF', 'AREA_UNAVAILABLE', 'OTHER'], true)) {
            throw ValidationException::withMessages(['stop_reason' => 'Please choose why you are stopping for now.']);
        }

        return $reason === 'AREA_UNAVAILABLE' ? WorkSessionOutcome::Paused : WorkSessionOutcome::Continuation;
    }

    private function blockerOutcome(string $reason): WorkSessionOutcome
    {
        if (! in_array($reason, ['MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART', 'TOOLS_UNAVAILABLE', 'NO_ACCESS', 'NEED_STAFF_HELP', 'EXTERNAL_SERVICE', 'FURTHER_INSPECTION', 'SAFETY', 'OTHER'], true)) {
            throw ValidationException::withMessages(['blocker_reason' => 'Please choose what is preventing you from continuing.']);
        }

        return match ($reason) {
            'MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART' => WorkSessionOutcome::WaitingForMaterials,
            'EXTERNAL_SERVICE', 'FURTHER_INSPECTION' => WorkSessionOutcome::NeedsFurtherInvestigation,
            default => WorkSessionOutcome::Paused,
        };
    }

    /** @param array<string, mixed> $data */
    private function blockerSentence(array $data): string
    {
        $material = trim((string) ($data['material_description'] ?? ''));

        return match ($data['blocker_reason'] ?? null) {
            'MATERIALS_UNAVAILABLE', 'REPLACEMENT_PART' => $material !== ''
                ? "Work cannot continue because {$material} is required. The task will resume once the material is available."
                : 'Work cannot continue because replacement materials are required. The task will resume once the necessary materials are available.',
            'EXTERNAL_SERVICE', 'FURTHER_INSPECTION' => 'The exact cause could not yet be confirmed. Further investigation is required before the repair can continue.',
            'TOOLS_UNAVAILABLE' => 'Work is paused because the required tools or equipment are unavailable.',
            'NO_ACCESS' => 'Work is paused because the area cannot currently be accessed.',
            'NEED_STAFF_HELP' => 'Work is paused until help from another staff member is available.',
            'SAFETY' => 'Work is paused because of a safety concern.',
            default => 'Work is paused until the reported blocker is resolved.',
        };
    }
}
