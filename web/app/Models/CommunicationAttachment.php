<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommunicationAttachment extends Model
{
    use HasUlids;

    protected $fillable = [
        'uploaded_by', 'purpose', 'original_filename', 'stored_path', 'thumbnail_path',
        'mime_type', 'file_size', 'width', 'height', 'thumbnail_width',
        'thumbnail_height', 'caption',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function safeDownloadName(): string
    {
        $basename = basename(str_replace('\\', '/', $this->original_filename));
        $safe = preg_replace('/[^\pL\pN._ -]/u', '_', $basename);
        $safe = trim($safe ?? '', ' .');

        return $safe === '' ? 'attachment' : $safe;
    }
}
