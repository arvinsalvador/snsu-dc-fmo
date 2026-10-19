<?php

namespace App\Services;

use App\Models\FmoPersonnel;

class PersonnelSkillService
{
    /** @param array<int, int|string> $skillIds */
    public function sync(FmoPersonnel $personnel, array $skillIds, int|string|null $primarySkillId): void
    {
        $skills = collect($skillIds)->map(fn (int|string $id): int => (int) $id)->unique()->values();
        $primary = $primarySkillId === null ? null : (int) $primarySkillId;

        if ($primary !== null && ! $skills->containsStrict($primary)) {
            $skills->push($primary);
        }

        $personnel->skills()->sync($skills->mapWithKeys(fn (int $skillId): array => [
            $skillId => ['is_primary' => $primary !== null && $skillId === $primary],
        ])->all());
    }
}
