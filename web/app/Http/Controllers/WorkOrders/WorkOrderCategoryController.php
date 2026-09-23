<?php

namespace App\Http\Controllers\WorkOrders;

use App\Http\Controllers\Controller;
use App\Models\WorkOrderCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderCategoryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_order_categories.view'), 403);
        $search = $request->string('search')->trim()->value();

        return view('work-order-categories.index', ['categories' => WorkOrderCategory::withCount('workOrders')->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))->orderBy('display_order')->orderBy('name')->paginate(20)->withQueryString(), 'search' => $search]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('work_order_categories.create'), 403);
        WorkOrderCategory::create($this->validated($request));

        return back()->with('success', 'Work order category created.');
    }

    public function update(Request $request, WorkOrderCategory $workOrderCategory)
    {
        abort_unless($request->user()->can('work_order_categories.update'), 403);
        $data = $this->validated($request, $workOrderCategory);
        if ($workOrderCategory->is_active && ! $data['is_active']) {
            abort_unless($request->user()->can('work_order_categories.manage_status'), 403);
        }
        $workOrderCategory->update($data);

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
        return $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('work_order_categories', 'name')->ignore($category?->id)], 'description' => ['nullable', 'string', 'max:2000'], 'display_order' => ['nullable', 'integer', 'min:0'], 'is_active' => ['required', 'boolean']]);
    }
}
