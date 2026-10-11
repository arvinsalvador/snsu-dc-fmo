<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EvidenceMediaService
{
    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly StoredFileTransaction $files) {}

    /** @return array<string, int|string|null> */
    public function store(UploadedFile $file, string $directory, string $field = 'evidence'): array
    {
        $mime = $file->getMimeType();
        if (! isset(self::IMAGE_MIMES[$mime])) {
            $path = $this->files->store($file, $directory);

            return $this->attributes($file, $path, null, $mime, $file->getSize(), null, null, null, null);
        }

        if (! extension_loaded('gd')) {
            throw ValidationException::withMessages([$field => 'Image processing is unavailable. Please contact the system administrator.']);
        }

        $sourcePath = $file->getRealPath();
        $info = @getimagesize($sourcePath);
        if (! $info || ($info['mime'] ?? null) !== $mime) {
            throw ValidationException::withMessages([$field => 'We could not process this image. Please choose a valid JPEG, PNG, or WebP photo.']);
        }

        [$sourceWidth, $sourceHeight] = $info;
        $maxInput = config('work_orders.attachments.image_max_input_dimension');
        $maxPixels = config('work_orders.attachments.image_max_pixels');
        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth > $maxInput || $sourceHeight > $maxInput || ($sourceWidth * $sourceHeight) > $maxPixels) {
            throw ValidationException::withMessages([$field => 'This image has unsupported pixel dimensions. Choose a photo no larger than '.number_format($maxInput).' pixels on either side.']);
        }

        $bytes = @file_get_contents($sourcePath);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages([$field => 'We could not process this image. Please choose another photo.']);
        }

        try {
            $image = $this->orient($image, $mime, $sourcePath);
            $normalized = $this->resizeWithin($image, config('work_orders.attachments.image_max_dimension'));
            $thumbnail = $this->resizeWithin($normalized, config('work_orders.attachments.thumbnail_max_dimension'));
            $extension = self::IMAGE_MIMES[$mime];
            $base = (string) Str::ulid();
            $path = trim($directory, '/').'/'.$base.'.'.$extension;
            $thumbnailPath = trim($directory, '/').'/'.$base.'-thumb.'.$extension;
            $normalizedBytes = $this->encode($normalized, $mime, $field);
            $thumbnailBytes = $this->encode($thumbnail, $mime, $field);
            $this->files->put($path, $normalizedBytes);
            $this->files->put($thumbnailPath, $thumbnailBytes);

            return $this->attributes(
                $file,
                $path,
                $thumbnailPath,
                $mime,
                strlen($normalizedBytes),
                imagesx($normalized),
                imagesy($normalized),
                imagesx($thumbnail),
                imagesy($thumbnail),
            );
        } finally {
            $resources = [];
            foreach ([$thumbnail ?? null, $normalized ?? null, $image] as $resource) {
                if ($resource instanceof GdImage) {
                    $resources[spl_object_id($resource)] = $resource;
                }
            }
            foreach ($resources as $resource) {
                imagedestroy($resource);
            }
        }
    }

    private function orient(GdImage $image, string $mime, string $path): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path, 'IFD0', true);
        $orientation = is_array($exif) ? (int) ($exif['IFD0']['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    private function resizeWithin(GdImage $source, int $maxDimension): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, $maxDimension / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));
        if ($targetWidth === $width && $targetHeight === $height) {
            return $source;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function encode(GdImage $image, string $mime, string $field): string
    {
        ob_start();
        $success = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, config('work_orders.attachments.image_quality')),
            'image/png' => imagepng($image, null, config('work_orders.attachments.png_compression')),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, null, config('work_orders.attachments.image_quality')),
            default => false,
        };
        $contents = ob_get_clean();
        if (! $success || ! is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([$field => 'We could not process this image. Please choose another photo.']);
        }

        return $contents;
    }

    /** @return array<string, int|string|null> */
    private function attributes(UploadedFile $file, string $path, ?string $thumbnailPath, string $mime, int $size, ?int $width, ?int $height, ?int $thumbnailWidth, ?int $thumbnailHeight): array
    {
        return [
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mime,
            'file_size' => $size,
            'width' => $width,
            'height' => $height,
            'thumbnail_width' => $thumbnailWidth,
            'thumbnail_height' => $thumbnailHeight,
        ];
    }
}
