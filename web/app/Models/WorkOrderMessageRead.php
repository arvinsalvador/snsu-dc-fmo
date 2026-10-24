<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderMessageRead extends Model
{
    protected $fillable = ['work_order_id', 'user_id', 'last_read_at'];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }
}
