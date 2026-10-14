<?php

namespace Database\Seeders;

use App\Models\AssetType;
use App\Models\GuidedDescriptionProfile;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Seeder;

class RoutineOperationsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->profiles() as $profile) {
            GuidedDescriptionProfile::updateOrCreate(['code' => $profile['code']], $profile);
        }

        $categoryMap = [
            'Electrical' => 'ELECTRICAL', 'Plumbing' => 'PLUMBING', 'Carpentry / Woodworking' => 'CARPENTRY',
            'Air Conditioning' => 'AIR_CONDITIONING', 'General Repair / Maintenance' => 'GENERAL_MAINTENANCE',
            'Other' => 'GENERAL_MAINTENANCE',
        ];
        foreach ($categoryMap as $category => $profileCode) {
            WorkOrderCategory::where('name', $category)->update([
                'guided_description_profile_id' => GuidedDescriptionProfile::where('code', $profileCode)->value('id'),
            ]);
        }

        foreach ($this->assetTypes() as $type) {
            AssetType::updateOrCreate(['code' => $type['code']], $type);
        }
    }

    /** @return list<array<string, mixed>> */
    private function profiles(): array
    {
        $outcomes = [
            ['code' => 'RESOLVED', 'label' => 'Issue resolved', 'clause' => 'The task was completed successfully.'],
            ['code' => 'PARTIAL', 'label' => 'Partially completed', 'clause' => 'The task was partially completed and follow-up work remains.'],
            ['code' => 'WAITING_SUPPLIES', 'label' => 'Waiting for materials or supplies', 'clause' => 'Further work is waiting for the required materials or supplies.'],
            ['code' => 'INVESTIGATION', 'label' => 'Needs further investigation', 'clause' => 'Further investigation is required before the work can be completed.'],
        ];
        $profile = static fn (string $code, string $name, array $actions, string $context = 'WORK_ORDER'): array => [
            'code' => $code, 'name' => $name, 'context' => $context, 'actions' => [...$actions, ['code' => 'OTHER_ACTION', 'label' => 'Other related work', 'clause' => 'completed other related work described in the remarks']],
            'outcomes' => $outcomes, 'sentence_templates' => ['version' => 1], 'is_active' => true,
        ];

        return [
            $profile('ELECTRICAL', 'Electrical work', [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'REPLACE_OUTLET', 'label' => 'Replaced damaged outlet', 'clause' => 'replaced the damaged electrical outlet'],
                ['code' => 'REPAIR_WIRING', 'label' => 'Repaired wiring', 'clause' => 'repaired the affected wiring'],
                ['code' => 'TEST_REPAIR', 'label' => 'Tested after repair', 'clause' => 'tested the installation after repair'],
            ]),
            $profile('PLUMBING', 'Plumbing work', [
                ['code' => 'INSPECT_LEAK', 'label' => 'Inspected leak or fixture', 'clause' => 'inspected the affected plumbing fixture'],
                ['code' => 'CLEAR_BLOCKAGE', 'label' => 'Cleared blockage', 'clause' => 'cleared the blockage'],
                ['code' => 'REPLACE_PART', 'label' => 'Replaced damaged part', 'clause' => 'replaced the damaged plumbing component'],
                ['code' => 'TEST_FLOW', 'label' => 'Tested water flow', 'clause' => 'tested the water flow and checked for leaks'],
            ]),
            $profile('CARPENTRY', 'Carpentry and woodworking', [
                ['code' => 'INSPECT_DAMAGE', 'label' => 'Inspected damaged part', 'clause' => 'inspected the damaged component'],
                ['code' => 'REPAIR_COMPONENT', 'label' => 'Repaired component', 'clause' => 'repaired the affected wooden component'],
                ['code' => 'REPLACE_HARDWARE', 'label' => 'Replaced hardware', 'clause' => 'replaced damaged hardware'],
                ['code' => 'CHECK_OPERATION', 'label' => 'Checked operation', 'clause' => 'checked that the repaired item operates correctly'],
            ]),
            $profile('AIR_CONDITIONING', 'Air-conditioning work', [
                ['code' => 'INSPECT_UNIT', 'label' => 'Inspected unit', 'clause' => 'inspected the air-conditioning unit'],
                ['code' => 'CLEAN_FILTER', 'label' => 'Cleaned filter', 'clause' => 'cleaned the air filter'],
                ['code' => 'CLEAN_UNIT', 'label' => 'Cleaned unit', 'clause' => 'cleaned the unit and accessible components'],
                ['code' => 'TEST_COOLING', 'label' => 'Tested cooling', 'clause' => 'tested the unit for proper cooling'],
            ]),
            $profile('GENERAL_MAINTENANCE', 'General maintenance', [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'REPAIR_ITEM', 'label' => 'Repaired item', 'clause' => 'completed the necessary repair'],
                ['code' => 'REPLACE_PART', 'label' => 'Replaced part', 'clause' => 'replaced the damaged component'],
                ['code' => 'TEST_WORK', 'label' => 'Tested completed work', 'clause' => 'tested the completed work'],
            ]),
            $profile('CLEANING_SURROUNDINGS', 'Cleaning surroundings', [
                ['code' => 'SWEEP_AREA', 'label' => 'Swept the area', 'clause' => 'swept the assigned area'],
                ['code' => 'REMOVE_LITTER', 'label' => 'Removed litter', 'clause' => 'removed litter and loose waste'],
                ['code' => 'CLEAR_PATHS', 'label' => 'Cleared walkways', 'clause' => 'cleared the surrounding walkways'],
            ], 'ROUTINE'),
            $profile('COMFORT_ROOM_CLEANING', 'Comfort room cleaning', [
                ['code' => 'CLEAN_FIXTURES', 'label' => 'Cleaned fixtures', 'clause' => 'cleaned and disinfected the fixtures'],
                ['code' => 'MOP_FLOOR', 'label' => 'Mopped floor', 'clause' => 'mopped and dried the floor'],
                ['code' => 'RESTOCK_SUPPLIES', 'label' => 'Restocked supplies', 'clause' => 'restocked available hygiene supplies'],
            ], 'ROUTINE'),
            $profile('GARBAGE_DISPOSAL', 'Garbage disposal', [
                ['code' => 'COLLECT_WASTE', 'label' => 'Collected waste', 'clause' => 'collected waste from the assigned bins'],
                ['code' => 'SEGREGATE_WASTE', 'label' => 'Segregated waste', 'clause' => 'segregated the collected waste'],
                ['code' => 'CLEAN_BINS', 'label' => 'Cleaned bins', 'clause' => 'cleaned and returned the bins'],
            ], 'ROUTINE'),
            $profile('GRASS_CUTTING', 'Grass cutting', [
                ['code' => 'CUT_GRASS', 'label' => 'Cut grass', 'clause' => 'cut the grass in the assigned area'],
                ['code' => 'TRIM_EDGES', 'label' => 'Trimmed edges', 'clause' => 'trimmed the edges and hard-to-reach sections'],
                ['code' => 'CLEAR_CLIPPINGS', 'label' => 'Cleared clippings', 'clause' => 'collected and cleared the grass clippings'],
            ], 'ROUTINE'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function assetTypes(): array
    {
        return [
            ['code' => 'FE', 'name' => 'Fire Extinguisher', 'checklist' => $this->checks(['Unit is present', 'Safety pin intact', 'Seal intact', 'Pressure within normal range', 'No visible damage', 'Access unobstructed', 'Inspection tag present']), 'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE', 'NEEDS_REFILL', 'EXPIRED'], 'is_active' => true],
            ['code' => 'EL', 'name' => 'Emergency Light', 'checklist' => $this->checks(['Unit present', 'Housing intact', 'Charging indicator normal', 'Light activates', 'Battery backup functions', 'Mounting secure']), 'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE'], 'is_active' => true],
            ['code' => 'AC', 'name' => 'Air Conditioner', 'checklist' => $this->checks(['Unit present', 'Filter condition acceptable', 'Cooling functions', 'No unusual noise', 'No visible leak', 'Mounting secure']), 'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE'], 'is_active' => true],
            ['code' => 'WF', 'name' => 'Wall Fan', 'checklist' => $this->checks(['Unit present', 'Guard intact', 'Blades unobstructed', 'Speed controls work', 'Mounting secure']), 'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE'], 'is_active' => true],
            ['code' => 'CF', 'name' => 'Ceiling Fan', 'checklist' => $this->checks(['Unit present', 'Blades intact', 'No excessive vibration', 'Speed controls work', 'Mounting secure']), 'result_options' => ['GOOD', 'NEEDS_ATTENTION', 'DEFECTIVE', 'MISSING', 'OUT_OF_SERVICE'], 'is_active' => true],
        ];
    }

    private function checks(array $labels): array
    {
        return collect($labels)->values()->map(fn (string $label, int $index): array => [
            'code' => 'CHECK_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'label' => $label,
        ])->all();
    }
}
