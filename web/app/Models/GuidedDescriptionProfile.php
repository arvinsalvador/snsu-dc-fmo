<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GuidedDescriptionProfile extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'name', 'context', 'actions', 'outcomes', 'sentence_templates', 'is_active'];

    protected function casts(): array
    {
        return ['actions' => 'array', 'outcomes' => 'array', 'sentence_templates' => 'array', 'is_active' => 'boolean'];
    }
}
