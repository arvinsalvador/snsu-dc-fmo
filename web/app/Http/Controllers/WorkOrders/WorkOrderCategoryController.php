<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Models\WorkOrderCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkOrderCategoryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_order_categories.view'), 403);
        $search = $request->string('search')->trim()->value();

        return view('work-order-categories.index', [
            'categories' => WorkOrderCategory::with('relevantSkills')->withCount('workOrders')->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))->orderBy('display_order')->orderBy('name')->paginate(20)->withQueryString(),
            'skills' => Skill::where('is_active', true)->orderBy('name')->get(),
            'search' => $search,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('work_order_categories.create'), 403);
        $data = $this->validated($request);
        $skillIds = $data['relevant_skill_ids'] ?? [];
        unset($data['relevant_skill_ids']);
        DB::transaction(function () use ($data, $skillIds): void {
            WorkOrderCategory::create($data)->relevantSkills()->sync($skillIds);
        });

        return back()->with('success', 'Work order category created.');
    }

    public function update(Request $request, WorkOrderCategory $workOrderCategory)
    {
        abort_unless($request->user()->can('work_order_categories.update'), 403);
        $data = $this->validated($request, $workOrderCategory);
        $skillIds = $data['relevant_skill_ids'] ?? [];
        unset($data['relevant_skill_ids']);
        if ($workOrderCategory->is_active && ! $data['is_active']) {
            abort_unless($request->user()->can('work_order_categories.manage_status'), 403);
        }
        DB::transaction(function () use ($request, $workOrderCategory, $data, $skillIds): void {
            $workOrderCategory->update($data);
            if ($request->boolean('relevant_skill_ids_present')) {
                $workOrderCategory->relevantSkills()->sync($skillIds);
            }
        });

        return back()->with('success', 'Work order category updated.');
    }

    public function destroy(Request $request, WorkOrderCategory $workOrderCategory)
    {
        abort_unless($request->user()->can('work_order_categories.delete'), 403);
        abort_if($workOrderCategory->workOrders()->exists(), 409, 'Referenced categories must be deactivated instead.');
        $workOrderCategory->delete();

        return back()->with('success', 'Unused work order category deleted.');
    }

    private function validated(Request $request, ?WorkOrderCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('work_order_categories', 'name')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'relevant_skill_ids' => ['sometimes', 'array'],
            'relevant_skill_ids.*' => ['integer', 'distinct', Rule::exists('skills', 'id')->where('is_active', true)],
        ]);
    }
}
