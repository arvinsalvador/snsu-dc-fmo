<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreventiveMaintenanceReading extends Model
{
    use HasUlids;

    protected $fillable = ['preventive_maintenance_execution_id', 'reading_key', 'label', 'value', 'unit'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(PreventiveMaintenanceExecution::class, 'preventive_maintenance_execution_id');
    }
}
