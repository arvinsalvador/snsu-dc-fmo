<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

class CommunicationMediaService
{
    public function __construct(private readonly EvidenceMediaService $media) {}

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Model $parent, array $files, User $uploader, string $purpose, string $field = 'attachments'): void
    {
        foreach ($files as $file) {
            $attributes = $this->media->store(
                $file,
                'communications/'.str($purpose)->lower()->replace('_', '-').'/'.$parent->getKey(),
                $field,
            );

            $parent->attachments()->create([
                ...$attributes,
                'uploaded_by' => $uploader->id,
                'purpose' => $purpose,
            ]);
        }
    }
}
