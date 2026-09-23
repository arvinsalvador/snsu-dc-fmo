<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SkillController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('skills.view'), 403);
        $search = $request->string('search')->trim()->value();

        return view('skills.index', [
            'skills' => Skill::withCount('personnel')->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')->paginate(20)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('skills.create'), 403);
        Skill::create($this->validated($request));

        return back()->with('success', 'Skill created.');
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        abort_unless($request->user()->can('skills.update'), 403);
        $skill->update($this->validated($request, $skill));

        return back()->with('success', 'Skill updated.');
    }

    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        abort_unless($request->user()->can('skills.delete'), 403);
        abort_if($skill->personnel()->exists(), 409, 'Referenced skills must be deactivated instead.');
        $skill->delete();

        return back()->with('success', 'Unused skill deleted.');
    }

    private function validated(Request $request, ?Skill $skill = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('skills', 'name')->ignore($skill?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
