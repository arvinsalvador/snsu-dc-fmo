<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreventiveMaintenanceChecklistResult extends Model
{
    use HasUlids;

    protected $fillable = ['preventive_maintenance_execution_id', 'item_key', 'label', 'result', 'notes', 'display_order'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenanceExecution::class, 'preventive_maintenance_execution_id');
    }
}
