<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\PersonnelStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function isAssignable(): bool
    {
        return $this->personnel_status === PersonnelStatus::Active
            && $this->user?->isApproved();
    }
}
