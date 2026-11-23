<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Serves a user's profile picture to any signed-in, approved user. A picture identifies a person
 * on shared records, so it is not tied to a management permission; the file path is never exposed.
 */
class AvatarController extends Controller
{
    private const TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function show(Request $request, User $user)
    {
        $path = $user->avatar_path;
        abort_unless($path && str_starts_with($path, 'avatars/'.$user->id.'/') && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="avatar-'.$user->id.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->setEtag(md5($path.'|'.$user->avatar_updated_at?->timestamp));

        $response->isNotModified($request);

        return $response;
    }
}
