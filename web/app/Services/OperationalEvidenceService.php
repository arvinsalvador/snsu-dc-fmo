<?php

namespace App\Services;

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
                $directory = match (class_basename($target)) {
                    'InspectableAsset' => 'asset-photos/'.$target->id,
                    'InspectionItem' => 'inspection-evidence/'.$target->id,
                    'AssetActionRequest' => 'asset-action-evidence/'.$target->id,
                    'AssetMaintenanceRecord' => 'asset-maintenance-evidence/'.$target->id,
                    default => 'routine-evidence/'.$target->id,
                };
                $target->attachments()->create($this->media->store($upload, $directory, 'evidence') + [
                    'uploaded_by' => $userId, 'purpose' => $purpose, 'evidence_type' => 'PHOTO',
                ]);
            }
        });
    }
}
