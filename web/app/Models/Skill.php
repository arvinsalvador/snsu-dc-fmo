<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function personnel(): BelongsToMany
    {
        return $this->belongsToMany(FmoPersonnel::class, 'fmo_personnel_skill')
            ->withPivot('is_primary')->withTimestamps();
    }
}
