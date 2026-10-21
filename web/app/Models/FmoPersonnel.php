<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\PersonnelStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FmoPersonnel extends Model
{
    use HasUlids;

    protected $table = 'fmo_personnel';

    protected $fillable = [
        'user_id', 'personnel_identifier', 'designation', 'employment_type',
        'start_date', 'contact_number', 'personnel_status', 'notes', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'personnel_status' => PersonnelStatus::class,
            'start_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'fmo_personnel_skill')
            ->withPivot('is_primary')->withTimestamps();
    }

    /** @return Collection<int, Skill> */
    public function orderedSkills(): Collection
    {
        $this->loadMissing('skills');

        return $this->skills
            ->sortBy(fn (Skill $skill): array => [
                $skill->pivot->is_primary ? 0 : 1,
                mb_strtolower($skill->name),
            ])
            ->values();
    }

    public function skillSummary(int $limit = 3, bool $identifyPrimary = false): string
    {
        $skills = $this->orderedSkills();
        if ($skills->isEmpty()) {
            return 'Skills not yet configured';
        }

        $summary = $skills->take($limit)
            ->map(fn (Skill $skill): string => $identifyPrimary && $skill->pivot->is_primary
                ? 'Primary: '.$skill->name
                : $skill->name)
            ->join(' • ');
        $remaining = $skills->count() - min($limit, $skills->count());

        return $remaining > 0 ? $summary.' • +'.$remaining.' more' : $summary;
    }

    public function isAssignable(): bool
    {
        return $this->personnel_status === PersonnelStatus::Active
            && $this->archived_at === null
            && $this->user?->isApproved();
    }

    public function workOrderAssignments(): HasMany
    {
        return $this->hasMany(WorkOrderAssignment::class);
    }

    public function preferredWorkOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_personnel_preferences')
            ->withTimestamps();
    }

    public function routineTemplateAssignments(): HasMany
    {
        return $this->hasMany(RoutineTaskTemplateAssignment::class);
    }

    public function inspectionItemAssignments(): HasMany
    {
        return $this->hasMany(InspectionItemAssignment::class);
    }

    public function assetActionAssignments(): HasMany
    {
        return $this->hasMany(AssetActionAssignment::class);
    }
}
