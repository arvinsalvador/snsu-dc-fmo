<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InspectionItem extends Model
{
    use HasUlids;

    protected $fillable = [
        'inspection_run_id', 'inspectable_asset_id', 'status', 'inspector_personnel_id', 'inspector_user_id',
        'inspected_at', 'result', 'condition', 'recommended_action', 'checklist_results', 'structured_actions', 'generated_summary', 'remarks',
    ];

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime', 'checklist_results' => 'array', 'structured_actions' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(InspectionRun::class, 'inspection_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InspectableAsset::class, 'inspectable_asset_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(FmoPersonnel::class, 'inspector_personnel_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(OperationalAttachment::class, 'attachable');
    }
}
