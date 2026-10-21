<?php

namespace App\Services;

use App\Enums\PersonnelStatus;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WorkOrderPreferenceService
{
    public function eligibleQuery(WorkOrderCategory $category): Builder
    {
        $skillIds = $category->loadMissing('relevantSkills')->relevantSkills->modelKeys();

        return FmoPersonnel::query()
            ->with(['user', 'skills'])
            ->where('personnel_status', PersonnelStatus::Active->value)
            ->whereNull('archived_at')
            ->whereHas('user', fn (Builder $query) => $query->where('status', 'APPROVED'))
            ->whereHas('skills', fn (Builder $query) => $query->whereIn('skills.id', $skillIds));
    }

    /** @return Collection<int, FmoPersonnel> */
    public function eligiblePersonnel(WorkOrderCategory $category): Collection
    {
        if ($category->loadMissing('relevantSkills')->relevantSkills->isEmpty()) {
            return collect();
        }

        return $this->eligibleQuery($category)->get()
            ->sortBy(fn (FmoPersonnel $person): string => mb_strtolower($person->user->name))
            ->values();
    }

    /** @param array<int, string> $personnelIds
     * @return array<int, string>
     */
    public function validateSelection(WorkOrderCategory $category, array $personnelIds): array
    {
        $ids = collect($personnelIds)->filter()->uniqueStrict()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        if ($category->loadMissing('relevantSkills')->relevantSkills->isEmpty()
            || $this->eligibleQuery($category)->whereKey($ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'preferred_fmo_personnel_ids' => 'One or more selected preferred personnel do not match the selected Work Type.',
            ]);
        }

        return $ids->all();
    }

    /** @param array<int, string> $personnelIds */
    public function sync(WorkOrder $workOrder, array $personnelIds): void
    {
        $workOrder->preferredPersonnel()->sync($personnelIds);
    }

    /** @param Collection<int, int> $relevantSkillIds
     * @return array<string, mixed>
     */
    public function requesterData(FmoPersonnel $personnel, Collection $relevantSkillIds): array
    {
        $skills = $personnel->orderedSkills();
        $primary = $skills->first(fn (Skill $skill): bool => (bool) $skill->pivot->is_primary);
        $matching = $skills->whereIn('id', $relevantSkillIds)->values();
        $additional = $skills->reject(fn (Skill $skill): bool => $matching->contains('id', $skill->id)
            || $primary?->is($skill))->values();

        return [
            'id' => $personnel->id,
            'name' => $personnel->user->name,
            'designation' => $personnel->designation,
            'primary_skill' => $primary?->name,
            'primary_matches' => $primary !== null && $matching->contains('id', $primary->id),
            'skills' => $skills->pluck('name')->values(),
            'matching_skills' => $matching->pluck('name')->values(),
            'additional_skills' => $additional->take(3)->pluck('name')->values(),
            'additional_skill_count' => max(0, $additional->count() - 3),
        ];
    }
}
