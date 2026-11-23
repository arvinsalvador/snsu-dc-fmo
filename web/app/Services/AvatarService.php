<?php

namespace App\Services;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stores one square, compressed profile picture per user on the private local disk. */
class AvatarService
{
    public const SIZE = 320;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly StoredFileTransaction $files) {}

    /** Replace the user's picture. The previous file is removed only after the new one is saved. */
    public function replace(User $user, UploadedFile $upload): void
    {
        $previous = $user->avatar_path;
        $this->files->run(function () use ($user, $upload): void {
            $path = 'avatars/'.$user->id.'/'.Str::ulid().'.'.$this->process($upload, $contents);
            $this->files->put($path, $contents);
            $user->forceFill(['avatar_path' => $path, 'avatar_updated_at' => now(config('app.timezone'))])->save();
        });
        $this->delete($previous);
    }

    public function remove(User $user): void
    {
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null, 'avatar_updated_at' => now(config('app.timezone'))])->save();
        $this->delete($previous);
    }

    /** Only paths this service created for the given user are ever deleted. */
    private function delete(?string $path): void
    {
        if ($path && str_starts_with($path, 'avatars/')) {
            Storage::disk('local')->delete($path);
        }
    }

    /** Orient, crop to a centred square, and resize. Returns the file extension; the bytes land in $contents. */
    private function process(UploadedFile $upload, ?string &$contents): string
    {
        $mime = $upload->getMimeType();
        $info = @getimagesize($upload->getRealPath());
        if (! isset(self::TYPES[$mime]) || ! $info || ($info['mime'] ?? null) !== $mime || ! extension_loaded('gd')) {
            throw ValidationException::withMessages(['photo' => 'Choose a valid JPG, PNG, or WebP image.']);
        }
        [$width, $height] = $info;
        $maxSide = (int) config('work_orders.attachments.image_max_input_dimension');
        if ($width < 32 || $height < 32 || $width > $maxSide || $height > $maxSide || $width * $height > (int) config('work_orders.attachments.image_max_pixels')) {
            throw ValidationException::withMessages(['photo' => 'This image has unsupported dimensions. Use a photo of at least 32 pixels and no larger than '.number_format($maxSide).' pixels per side.']);
        }
        $source = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));
        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages(['photo' => 'We could not process this image. Please choose another photo.']);
        }

        $source = $this->orient($source, $mime, $upload->getRealPath());
        $side = min(imagesx($source), imagesy($source));
        $square = imagecrop($source, ['x' => intdiv(imagesx($source) - $side, 2), 'y' => intdiv(imagesy($source) - $side, 2), 'width' => $side, 'height' => $side]);
        if (! $square instanceof GdImage) {
            throw ValidationException::withMessages(['photo' => 'We could not process this image. Please choose another photo.']);
        }
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefilledrectangle($target, 0, 0, self::SIZE, self::SIZE, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $square, 0, 0, 0, 0, self::SIZE, self::SIZE, $side, $side);
        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($target, null, 85),
            'image/png' => imagepng($target, null, 7),
            default => function_exists('imagewebp') && imagewebp($target, null, 85),
        };
        $contents = (string) ob_get_clean();
        if (! $ok || $contents === '') {
            throw ValidationException::withMessages(['photo' => 'We could not process this image. Please choose another photo.']);
        }

        return self::TYPES[$mime];
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
            3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
