<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderCategory extends Model
{
    protected $fillable = ['name', 'description', 'guided_description_profile_id', 'is_active', 'display_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function guidedProfile(): BelongsTo
    {
        return $this->belongsTo(GuidedDescriptionProfile::class, 'guided_description_profile_id');
    }

    public function relevantSkills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'work_order_category_skill')
            ->withTimestamps();
    }
}
