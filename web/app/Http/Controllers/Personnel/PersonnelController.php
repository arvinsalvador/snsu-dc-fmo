<?php

namespace App\Http\Controllers\Personnel;

use App\Enums\AccountStatus;
use App\Enums\EmploymentType;
use App\Enums\PersonnelStatus;
use App\Http\Controllers\Controller;
use App\Models\FmoPersonnel;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PersonnelController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('personnel.view'), 403);
        $filters = $request->only(['search', 'status', 'skill', 'designation', 'employment_type']);
        $personnel = FmoPersonnel::query()->with(['user', 'skills'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('personnel_status', $v))
            ->when($filters['skill'] ?? null, fn ($q, $v) => $q->whereHas('skills', fn ($s) => $s->whereKey($v)))
            ->when($filters['designation'] ?? null, fn ($q, $v) => $q->where('designation', $v))
            ->when($filters['employment_type'] ?? null, fn ($q, $v) => $q->where('employment_type', $v))
            ->orderBy('personnel_status')->paginate(20)->withQueryString();

        return view('personnel.index', compact('personnel', 'filters') + [
            'skills' => Skill::orderBy('name')->get(),
            'designations' => FmoPersonnel::whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation'),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('personnel.create'), 403);

        return view('personnel.form', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('personnel.create'), 403);
        $data = $this->validated($request);
        $user = User::findOrFail($data['user_id']);
        abort_unless($user->status === AccountStatus::Approved && ! $user->personnelProfile()->exists(), 422);

        $personnel = DB::transaction(function () use ($data): FmoPersonnel {
            $personnel = FmoPersonnel::create($this->personnelFields($data));
            $this->syncSkills($personnel, $data);

            return $personnel;
        });

        return redirect()->route('personnel.show', $personnel)->with('success', 'FMO personnel profile created.');
    }

    public function show(Request $request, FmoPersonnel $personnel): View
    {
        abort_unless($request->user()->can('personnel.view') || $personnel->user_id === $request->user()->id, 403);

        return view('personnel.show', ['personnel' => $personnel->load('user.roles', 'skills')]);
    }

    public function edit(Request $request, FmoPersonnel $personnel): View
    {
        abort_unless($request->user()->can('personnel.update'), 403);

        return view('personnel.form', $this->formData($personnel));
    }

    public function update(Request $request, FmoPersonnel $personnel): RedirectResponse
    {
        abort_unless($request->user()->can('personnel.update'), 403);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($personnel, $data): void {
            $personnel->update($this->personnelFields($data, false));
            $this->syncSkills($personnel, $data);
        });

        return redirect()->route('personnel.show', $personnel)->with('success', 'FMO personnel profile updated.');
    }

    public function updateStatus(Request $request, FmoPersonnel $personnel): RedirectResponse
    {
        abort_unless($request->user()->can('personnel.manage_status'), 403);
        $data = $request->validate(['personnel_status' => ['required', Rule::enum(PersonnelStatus::class)]]);
        $personnel->update([
            'personnel_status' => $data['personnel_status'],
            'archived_at' => $data['personnel_status'] === PersonnelStatus::Inactive->value ? now() : null,
        ]);

        return back()->with('success', 'Personnel status updated.');
    }

    public function destroy(Request $request, FmoPersonnel $personnel): RedirectResponse
    {
        abort_unless($request->user()->can('personnel.delete'), 403);
        // Future work-order relations belong in this guard before profile deletion.
        $personnel->delete();

        return redirect()->route('personnel.index')->with('success', 'Unused personnel profile deleted. The user account was retained.');
    }

    private function formData(?FmoPersonnel $personnel = null): array
    {
        return [
            'personnel' => $personnel?->load('skills'),
            'users' => User::where('status', AccountStatus::Approved->value)->whereDoesntHave('personnelProfile')->orderBy('name')->get(),
            'skills' => Skill::where('is_active', true)->orderBy('name')->get(),
            'employmentTypes' => EmploymentType::cases(),
            'personnelStatuses' => PersonnelStatus::cases(),
        ];
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate(array_filter([
            'user_id' => $creating ? ['required', 'integer', Rule::exists('users', 'id')] : null,
            'personnel_identifier' => ['nullable', 'string', 'max:100', Rule::unique('fmo_personnel', 'personnel_identifier')->ignore($request->route('personnel')?->id)],
            'designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'start_date' => ['nullable', 'date'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'personnel_status' => ['required', Rule::enum(PersonnelStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'skill_ids' => ['array'],
            'skill_ids.*' => ['integer', Rule::exists('skills', 'id')],
            'primary_skill_id' => ['nullable', 'integer', Rule::exists('skills', 'id')],
        ]));
    }

    private function personnelFields(array $data, bool $creating = true): array
    {
        $fields = collect($data)->only(['personnel_identifier', 'designation', 'employment_type', 'start_date', 'contact_number', 'personnel_status', 'notes'])->all();
        if ($creating) {
            $fields['user_id'] = $data['user_id'];
        }

        return $fields;
    }

    private function syncSkills(FmoPersonnel $personnel, array $data): void
    {
        $skills = array_map('intval', $data['skill_ids'] ?? []);
        $primarySkillId = $data['primary_skill_id'] ?? null;
        abort_if($primarySkillId && ! in_array((int) $primarySkillId, $skills, true), 422);
        $sync = [];
        foreach ($skills as $skillId) {
            $sync[$skillId] = ['is_primary' => $skillId === (int) $primarySkillId];
        }
        $personnel->skills()->sync($sync);
    }
}
