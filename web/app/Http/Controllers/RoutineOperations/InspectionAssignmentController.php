<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\InspectionItem;
use App\Models\InspectionRun;
use App\Services\InspectionAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InspectionAssignmentController extends Controller
{
    public function run(Request $request, InspectionRun $inspection, InspectionAssignmentService $assignments)
    {
        abort_unless($request->user()->can('inspections.assign'), 403);
        $data = $this->validated($request);
        $this->assertAssignable($data['assignee_ids']);
        $assignments->replaceRunTeam($inspection, $data['assignee_ids'], $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Current inspection team updated. Completed asset inspections were preserved.');
    }

    public function item(Request $request, InspectionRun $inspection, InspectionItem $item, InspectionAssignmentService $assignments)
    {
        abort_unless($request->user()->can('inspections.assign'), 403);
        abort_unless($item->inspection_run_id === $inspection->id, 404);
        $data = $this->validated($request);
        $this->assertAssignable($data['assignee_ids']);
        $assignments->replaceItemTeam($item, $data['assignee_ids'], $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Assigned inspector updated for '.$item->asset()->value('asset_code').'.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'assignee_ids' => ['required', 'array', 'min:1'],
            'assignee_ids.*' => ['distinct', Rule::exists('fmo_personnel', 'id')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** @param array<int, string> $personnelIds */
    private function assertAssignable(array $personnelIds): void
    {
        $personnel = FmoPersonnel::with('user')->whereIn('id', $personnelIds)->get();
        if ($personnel->count() !== count(array_unique($personnelIds)) || $personnel->contains(fn (FmoPersonnel $person) => ! $person->isAssignable())) {
            throw ValidationException::withMessages(['assignee_ids' => 'Every assigned inspector must have an active, approved FMO personnel profile.']);
        }
    }
}
