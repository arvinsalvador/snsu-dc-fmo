<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\InspectionItem;
use App\Models\OperationalAttachment;
use App\Models\RoutineTaskOccurrence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OperationalAttachmentController extends Controller
{
    public function show(Request $request, OperationalAttachment $attachment, string $variant = 'original')
    {
        $target = $attachment->attachable;
        if ($target instanceof RoutineTaskOccurrence) {
            abort_unless($request->user()->can('routine_tasks.view_all') || ($request->user()->can('routine_tasks.view') && $target->assignees()->where('user_id', $request->user()->id)->exists()), 403);
        } elseif ($target instanceof InspectionItem) {
            abort_unless($request->user()->can('inspections.view_all') || ($request->user()->can('inspections.view') && $target->run->occurrence->assignees()->where('user_id', $request->user()->id)->exists()), 403);
        } else {
            abort(404);
        }
        $path = $variant === 'thumbnail' && $attachment->thumbnail_path ? $attachment->thumbnail_path : $attachment->stored_path;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $attachment->mime_type, 'Content-Disposition' => 'inline; filename="'.str_replace(['"', "\r", "\n"], '_', $attachment->original_filename).'"',
            'X-Content-Type-Options' => 'nosniff', 'Pragma' => 'no-cache',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
