<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrderCategory;
use Illuminate\Http\Request;

class WorkOrderCategoryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('work_order_categories.view'), 403);

        return response()->json(['data' => WorkOrderCategory::where('is_active', true)->orderBy('display_order')->orderBy('name')->get(['id', 'name', 'description'])]);
    }
}
