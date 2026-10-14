<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetType extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'name', 'checklist', 'result_options', 'is_active'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'result_options' => 'array', 'is_active' => 'boolean'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(InspectableAsset::class);
    }
}
