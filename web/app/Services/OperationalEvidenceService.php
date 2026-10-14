<?php

namespace App\Services;

use App\Models\InspectionItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

class OperationalEvidenceService
{
    public function __construct(private readonly StoredFileTransaction $files, private readonly EvidenceMediaService $media) {}

    /** @param list<UploadedFile> $uploads */
    public function attach(Model $target, array $uploads, int $userId, string $purpose): void
    {
        $this->files->run(function () use ($target, $uploads, $userId, $purpose): void {
            foreach ($uploads as $upload) {
                $directory = $target instanceof InspectionItem ? 'inspection-evidence/'.$target->id : 'routine-evidence/'.$target->id;
                $target->attachments()->create($this->media->store($upload, $directory, 'evidence') + [
                    'uploaded_by' => $userId, 'purpose' => $purpose, 'evidence_type' => 'PHOTO',
                ]);
            }
        });
    }
}
