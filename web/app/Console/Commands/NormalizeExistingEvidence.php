<?php

namespace App\Console\Commands;

use App\Models\WorkOrderAttachment;
use App\Services\EvidenceMediaService;
use App\Services\StoredFileTransaction;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class NormalizeExistingEvidence extends Command
{
    protected $signature = 'evidence:normalize-existing {--dry-run : Count legacy images without changing files}';

    protected $description = 'Normalize legacy private evidence images and generate protected thumbnails';

    public function handle(EvidenceMediaService $media, StoredFileTransaction $files): int
    {
        $query = WorkOrderAttachment::where('mime_type', 'like', 'image/%')->whereNull('thumbnail_path');
        if ($this->option('dry-run')) {
            $this->info($query->count().' legacy evidence image(s) need normalization and a thumbnail.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;
        $query->orderBy('id')->each(function (WorkOrderAttachment $attachment) use ($media, $files, &$processed, &$failed): void {
            $disk = Storage::disk('local');
            if (! $disk->exists($attachment->stored_path)) {
                $this->warn("Skipped {$attachment->id}: private source file is missing.");
                $failed++;

                return;
            }

            $oldPath = $attachment->stored_path;
            try {
                $attributes = $files->run(function () use ($attachment, $media, $disk): array {
                    $upload = new UploadedFile($disk->path($attachment->stored_path), $attachment->original_filename, $attachment->mime_type, null, true);
                    $directory = dirname($attachment->stored_path);
                    $attributes = $media->store($upload, $directory === '.' ? 'work-orders/'.$attachment->work_order_id : $directory, 'file');
                    $attachment->update($attributes);

                    return $attributes;
                });
                if ($attributes['stored_path'] !== $oldPath) {
                    $disk->delete($oldPath);
                }
                $processed++;
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Skipped {$attachment->id}: the image could not be processed.");
                $failed++;
            }
        });

        $this->info("Normalized {$processed} legacy evidence image(s); {$failed} skipped.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
