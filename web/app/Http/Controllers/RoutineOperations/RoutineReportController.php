<?php

namespace App\Http\Controllers\RoutineOperations;

use App\Http\Controllers\Controller;
use App\Models\RoutineTaskOccurrence;
use Illuminate\Http\Request;

class RoutineReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('reports.view_routine_operations'), 403);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'max:30'], 'frequency' => ['nullable', 'string', 'max:20']]);
        $occurrences = RoutineTaskOccurrence::with(['template.campus', 'template.building', 'performer.user'])
            ->when($data['from'] ?? null, fn ($q, $value) => $q->whereDate('expected_date', '>=', $value))
            ->when($data['to'] ?? null, fn ($q, $value) => $q->whereDate('expected_date', '<=', $value))
            ->when($data['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($data['frequency'] ?? null, fn ($q, $value) => $q->whereHas('template', fn ($t) => $t->where('frequency', $value)))
            ->latest('expected_date')->paginate(30)->withQueryString();

        return view('routine-operations.reports.index', compact('occurrences'));
    }
}
