<?php

namespace App\Http\Requests;

use App\Enums\EmploymentType;
use App\Enums\PersonnelStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->isMethod('post')
            ? $this->user()?->can('personnel.create')
            : $this->user()?->can('personnel.update'));
    }

    protected function prepareForValidation(): void
    {
        $skillIds = collect((array) $this->input('skill_ids', []))
            ->map(fn (mixed $id): mixed => is_numeric($id) ? (int) $id : $id)
            ->reject(fn (mixed $id): bool => $id === null || $id === '')
            ->uniqueStrict()
            ->values();

        $primarySkillId = $this->filled('primary_skill_id')
            ? (is_numeric($this->input('primary_skill_id')) ? (int) $this->input('primary_skill_id') : $this->input('primary_skill_id'))
            : null;

        if ($primarySkillId !== null && ! $skillIds->containsStrict($primarySkillId)) {
            $skillIds->push($primarySkillId);
        }

        $this->merge([
            'skill_ids' => $skillIds->all(),
            'primary_skill_id' => $primarySkillId,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_filter([
            'user_id' => $this->isMethod('post') ? [
                'required', 'integer',
                Rule::exists('users', 'id')->where('status', 'APPROVED'),
                Rule::unique('fmo_personnel', 'user_id'),
            ] : null,
            'personnel_identifier' => ['nullable', 'string', 'max:100', Rule::unique('fmo_personnel', 'personnel_identifier')->ignore($this->route('personnel'))],
            'designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'start_date' => ['nullable', 'date'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'personnel_status' => ['required', Rule::enum(PersonnelStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'skill_ids' => ['array'],
            'skill_ids.*' => ['integer', 'distinct', Rule::exists('skills', 'id')->where('is_active', true)],
            'primary_skill_id' => ['nullable', 'integer', Rule::exists('skills', 'id')->where('is_active', true)],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'Please select a linked approved user.',
            'user_id.unique' => 'The selected user already has an FMO personnel profile.',
            'skill_ids.*.exists' => 'Every assigned skill must be active and available.',
            'primary_skill_id.exists' => 'The primary skill must be active and available.',
        ];
    }
}
