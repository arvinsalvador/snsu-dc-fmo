<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProcessedClientOperation extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'client_operation_id', 'installation_id', 'type', 'request_hash', 'result', 'client_created_at', 'processed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'client_created_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
