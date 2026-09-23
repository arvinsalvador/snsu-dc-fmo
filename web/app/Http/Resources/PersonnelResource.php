<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonnelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'designation' => $this->designation,
            'employment_type' => $this->employment_type?->value,
            'personnel_status' => $this->personnel_status->value,
            'assignable' => $this->isAssignable(),
            'primary_skill' => $this->skills->firstWhere('pivot.is_primary', true)?->name,
            'skills' => $this->skills->pluck('name')->values(),
        ];
    }
}
