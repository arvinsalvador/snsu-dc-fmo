<?php

namespace App\Http\Controllers;

use App\Models\CommunicationAttachment;
use App\Models\FmoContactMessage;
use App\Models\WorkOrderInternalNote;
use App\Models\WorkOrderMessage;
use App\Services\CommunicationAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CommunicationAttachmentController extends Controller
{
    public function show(
        Request $request,
        CommunicationAttachment $attachment,
        CommunicationAuthorizationService $authorization,
        string $variant = 'original',
    ) {
        $parent = $attachment->attachable;
        $allowed = match (true) {
            $parent instanceof WorkOrderMessage => $authorization->canViewMessages($request->user(), $parent->workOrder),
            $parent instanceof WorkOrderInternalNote => $authorization->canViewInternalNotes($request->user(), $parent->workOrder),
            $parent instanceof FmoContactMessage => $authorization->canViewContactThread($request->user(), $parent->thread),
            default => false,
        };
        abort_unless($allowed, 403);

        $path = $variant === 'thumbnail' && $attachment->thumbnail_path
            ? $attachment->thumbnail_path
            : $attachment->stored_path;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $filename = str_replace(['"', "\r", "\n"], '_', $attachment->safeDownloadName());
        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
