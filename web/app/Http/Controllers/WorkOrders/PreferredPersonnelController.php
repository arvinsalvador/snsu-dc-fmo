<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\WorkOrderCategory;
use App\Services\WorkOrderPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferredPersonnelController extends Controller
{
    public function index(Request $request, WorkOrderCategory $workOrderCategory, WorkOrderPreferenceService $preferences): JsonResponse
    {
        abort_unless($request->user()->can('work_order_categories.view'), 403);
        abort_unless($workOrderCategory->is_active, 404);

        $relevantSkills = $workOrderCategory->load('relevantSkills')->relevantSkills;
        $personnel = $preferences->eligiblePersonnel($workOrderCategory)
            ->map(fn ($personnel): array => $preferences->requesterData($personnel, $relevantSkills->pluck('id')));

        return response()->json([
            'data' => $personnel,
            'state' => $relevantSkills->isEmpty() ? 'unmapped' : ($personnel->isEmpty() ? 'no_matches' : 'ready'),
            'mapping_configured' => $relevantSkills->isNotEmpty(),
            'relevant_skills' => $relevantSkills->pluck('name')->values(),
            'max_selections' => config('work_orders.preferences.max_personnel'),
        ]);
    }
}
