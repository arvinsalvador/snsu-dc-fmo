<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\AssetActionRequest;
use App\Models\AssetMaintenanceRecord;
use App\Models\InspectableAsset;
use App\Models\InspectionItem;
use App\Models\OperationalAttachment;
use App\Models\PreventiveMaintenanceExecution;
use App\Models\RoutineTaskOccurrence;
use App\Models\TransportRequest;
use App\Services\PreventiveMaintenanceAccessService;
use App\Services\RoutineTaskAccessService;
use App\Services\TransportAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OperationalAttachmentController extends Controller
{
    public function show(Request $request, OperationalAttachment $attachment, RoutineTaskAccessService $access, PreventiveMaintenanceAccessService $pmAccess, string $variant = 'original')
    {
        $target = $attachment->attachable;
        if ($target instanceof RoutineTaskOccurrence) {
            abort_unless($request->user()->can('routine_tasks.view_all') || ($request->user()->can('routine_tasks.view') && $access->assignedTo($target, $request->user())), 403);
        } elseif ($target instanceof InspectionItem) {
            $allowed = $target->run
                ? $request->user()->can('inspections.view_all') || ($request->user()->can('inspections.view') && $access->assignedToInspectionItem($target, $request->user()))
                : $request->user()->can('assets.view');
            abort_unless($allowed, 403);
        } elseif ($target instanceof InspectableAsset) {
            abort_unless($request->user()->can('assets.view'), 403);
        } elseif ($target instanceof AssetActionRequest || $target instanceof AssetMaintenanceRecord) {
            abort_unless($request->user()->can('assets.view'), 403);
        } elseif ($target instanceof PreventiveMaintenanceExecution) {
            $target->loadMissing('occurrence');
            abort_unless($request->user()->can('preventive_maintenance.view_all') || ($request->user()->can('preventive_maintenance.view') && $pmAccess->assignedTo($target->occurrence, $request->user())), 403);
        } elseif ($target instanceof TransportRequest) {
            $transport = app(TransportAccessService::class);
            $user = $request->user();
            abort_unless(match ($attachment->purpose) {
                TransportRequest::APPROVAL_EVIDENCE_PURPOSE => $transport->canSeeApprovalEvidence($user),
                TransportRequest::DOCUMENT_PURPOSE => $transport->canView($target, $user),
                default => $transport->canSeeTripDocuments($target, $user),
            }, 403);
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
