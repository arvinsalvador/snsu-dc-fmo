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
            'Welding' => 'WELDING', 'Fabrication / Craft' => 'WELDING', 'Painting' => 'PAINTING', 'Masonry' => 'MASONRY',
            'Air Conditioning' => 'AIR_CONDITIONING', 'General Repair / Maintenance' => 'GENERAL_MAINTENANCE',
            'Furniture / Fixture' => 'CARPENTRY', 'Grounds / Outdoor Maintenance' => 'GENERAL_MAINTENANCE', 'Other' => 'GENERIC',
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
            ['code' => 'RESOLVED', 'label' => 'Working Properly', 'description' => 'The reported issue is resolved.', 'clause' => 'The reported issue is now working properly.'],
            ['code' => 'PARTIAL', 'label' => 'More Work Is Needed', 'description' => 'Some work was completed, but additional work is still required.', 'clause' => 'Some work was completed, but additional work is still required.'],
            ['code' => 'WAITING_SUPPLIES', 'label' => 'Waiting for Materials', 'description' => 'Work cannot continue until materials or parts are available.', 'clause' => 'Work will continue when the required materials or parts are available.'],
            ['code' => 'PROBLEM_PERSISTS', 'label' => 'Problem Still Exists', 'description' => 'The work performed did not fully resolve the problem.', 'clause' => 'The problem still exists and requires additional work.'],
            ['code' => 'INVESTIGATION', 'label' => 'Further Inspection Is Needed', 'description' => 'More checking or diagnosis is needed.', 'clause' => 'Further inspection is required before the repair can continue.'],
        ];
        $profile = static fn (string $code, string $name, array $actions, string $context = 'WORK_ORDER'): array => [
            'code' => $code, 'name' => $name, 'context' => $context, 'actions' => [...$actions, ['code' => 'OTHER_ACTION', 'label' => 'Other '.$name, 'clause' => 'completed other related work']],
            'outcomes' => $outcomes, 'sentence_templates' => ['version' => 2], 'is_active' => true,
        ];

        return [
            $profile('ELECTRICAL', 'Electrical work', [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected the affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'CHECK_WIRING', 'label' => 'Checked electrical wiring', 'clause' => 'checked the electrical wiring'],
                ['code' => 'CHECK_OUTLET', 'label' => 'Checked the outlet', 'clause' => 'checked the electrical outlet'],
                ['code' => 'CHECK_SWITCH', 'label' => 'Checked the switch', 'clause' => 'checked the electrical switch'],
                ['code' => 'TEST_POWER', 'label' => 'Tested power or voltage', 'clause' => 'tested the power and voltage'],
                ['code' => 'TIGHTEN_CONNECTION', 'label' => 'Tightened a loose connection', 'clause' => 'tightened the loose electrical connection'],
                ['code' => 'REPLACE_OUTLET', 'label' => 'Replaced damaged outlet', 'clause' => 'replaced the damaged electrical outlet'],
                ['code' => 'REPLACE_SWITCH', 'label' => 'Replaced damaged switch', 'clause' => 'replaced the damaged electrical switch'],
                ['code' => 'REPLACE_WIRE', 'label' => 'Replaced damaged wire', 'clause' => 'replaced the damaged electrical wire'],
                ['code' => 'RESTORE_POWER', 'label' => 'Restored power', 'clause' => 'restored electrical power'],
                ['code' => 'TEST_REPAIR', 'label' => 'Tested after repair', 'clause' => 'tested the installation after repair'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('PLUMBING', 'Plumbing work', [
                ['code' => 'INSPECT_LEAK', 'label' => 'Inspected the reported leak', 'clause' => 'inspected the reported leak'],
                ['code' => 'CHECK_CONNECTION', 'label' => 'Checked the pipe connection', 'clause' => 'checked the pipe connection'],
                ['code' => 'TIGHTEN_FITTING', 'label' => 'Tightened the fitting', 'clause' => 'tightened the loose fitting'],
                ['code' => 'REPLACE_FITTING', 'label' => 'Replaced the fitting', 'clause' => 'replaced the damaged fitting'],
                ['code' => 'REPLACE_PIPE', 'label' => 'Replaced damaged pipe', 'clause' => 'replaced the damaged pipe'],
                ['code' => 'CLEAR_BLOCKAGE', 'label' => 'Cleared blockage', 'clause' => 'cleared the blockage'],
                ['code' => 'TEST_FLOW', 'label' => 'Tested water flow', 'clause' => 'tested the water flow and checked for leaks'],
                ['code' => 'CHECK_LEAKS', 'label' => 'Checked for leaks after repair', 'clause' => 'checked for leaks after the repair'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the affected area', 'clause' => 'cleaned the affected area'],
            ]),
            $profile('CARPENTRY', 'Carpentry and woodworking', [
                ['code' => 'INSPECT_DAMAGE', 'label' => 'Inspected the damaged component', 'clause' => 'inspected the damaged component'],
                ['code' => 'REMOVE_PART', 'label' => 'Removed the damaged part', 'clause' => 'removed the damaged part'],
                ['code' => 'REPAIR_COMPONENT', 'label' => 'Repaired the wooden component', 'clause' => 'repaired the affected wooden component'],
                ['code' => 'REPLACE_WOOD', 'label' => 'Replaced damaged wood', 'clause' => 'replaced the damaged wood'],
                ['code' => 'TIGHTEN_HINGES', 'label' => 'Tightened the hinges', 'clause' => 'tightened the loose hinges'],
                ['code' => 'REPLACE_HINGES', 'label' => 'Replaced the hinges', 'clause' => 'replaced the damaged hinges'],
                ['code' => 'ADJUST_ALIGNMENT', 'label' => 'Adjusted door or window alignment', 'clause' => 'adjusted the door or window alignment'],
                ['code' => 'SECURE_COMPONENT', 'label' => 'Secured the loose component', 'clause' => 'secured the loose component'],
                ['code' => 'TEST_REPAIR', 'label' => 'Tested after repair', 'clause' => 'tested the repaired component'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('WELDING', 'Welding work', [
                ['code' => 'INSPECT_METAL', 'label' => 'Inspected the damaged metal component', 'clause' => 'inspected the damaged metal component'],
                ['code' => 'PREPARE_SURFACE', 'label' => 'Prepared the surface for welding', 'clause' => 'prepared the surface for welding'],
                ['code' => 'WELD_JOINT', 'label' => 'Welded the damaged joint', 'clause' => 'welded the damaged joint'],
                ['code' => 'REINFORCE_SUPPORT', 'label' => 'Reinforced the metal support', 'clause' => 'reinforced the metal support'],
                ['code' => 'REPLACE_METAL', 'label' => 'Replaced the damaged metal part', 'clause' => 'replaced the damaged metal part'],
                ['code' => 'SMOOTH_WELD', 'label' => 'Ground or smoothed the welded area', 'clause' => 'ground and smoothed the welded area'],
                ['code' => 'CHECK_STABILITY', 'label' => 'Checked joint stability', 'clause' => 'checked the stability of the joint'],
                ['code' => 'TEST_STRUCTURE', 'label' => 'Tested the repaired structure', 'clause' => 'tested the repaired structure'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('AIR_CONDITIONING', 'Air-conditioning work', [
                ['code' => 'INSPECT_UNIT', 'label' => 'Inspected the air-conditioning unit', 'clause' => 'inspected the air-conditioning unit'],
                ['code' => 'CLEAN_FILTER', 'label' => 'Cleaned the air filter', 'clause' => 'cleaned the air filter'],
                ['code' => 'CLEAN_UNIT', 'label' => 'Cleaned the unit', 'clause' => 'cleaned the unit and accessible components'],
                ['code' => 'CHECK_ELECTRICAL', 'label' => 'Checked the electrical connection', 'clause' => 'checked the electrical connection'],
                ['code' => 'CHECK_DRAINAGE', 'label' => 'Checked the drainage', 'clause' => 'checked the drainage system'],
                ['code' => 'CHECK_COOLING', 'label' => 'Checked cooling performance', 'clause' => 'checked the cooling performance'],
                ['code' => 'CHECK_NOISE', 'label' => 'Checked unusual noise', 'clause' => 'checked the unit for unusual noise'],
                ['code' => 'CHECK_MOUNTING', 'label' => 'Checked the mounting', 'clause' => 'checked that the unit mounting is secure'],
                ['code' => 'TEST_SERVICE', 'label' => 'Tested after service', 'clause' => 'tested the unit after servicing'],
            ]),
            $profile('GENERAL_MAINTENANCE', 'General maintenance', [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected the affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the area', 'clause' => 'cleaned the affected area'],
                ['code' => 'TIGHTEN_COMPONENT', 'label' => 'Tightened a loose component', 'clause' => 'tightened the loose component'],
                ['code' => 'REPAIR_ITEM', 'label' => 'Repaired the damaged part', 'clause' => 'repaired the damaged part'],
                ['code' => 'REPLACE_PART', 'label' => 'Replaced the damaged part', 'clause' => 'replaced the damaged part'],
                ['code' => 'SECURE_COMPONENT', 'label' => 'Secured the component', 'clause' => 'secured the affected component'],
                ['code' => 'REMOVE_OBSTRUCTION', 'label' => 'Removed the obstruction', 'clause' => 'removed the obstruction'],
                ['code' => 'ADJUST_COMPONENT', 'label' => 'Adjusted the component', 'clause' => 'adjusted the affected component'],
                ['code' => 'TEST_WORK', 'label' => 'Tested after repair', 'clause' => 'tested the completed repair'],
                ['code' => 'CLEAN_WORK_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('MASONRY', 'Masonry work', [
                ['code' => 'INSPECT_MASONRY', 'label' => 'Inspected the damaged masonry', 'clause' => 'inspected the damaged masonry'],
                ['code' => 'PREPARE_SURFACE', 'label' => 'Prepared the affected surface', 'clause' => 'prepared the affected surface'],
                ['code' => 'APPLY_MORTAR', 'label' => 'Applied cement or mortar', 'clause' => 'applied cement or mortar'],
                ['code' => 'REPAIR_CRACK', 'label' => 'Repaired the crack', 'clause' => 'repaired the crack'],
                ['code' => 'REPAIR_WALL', 'label' => 'Repaired the damaged wall', 'clause' => 'repaired the damaged wall'],
                ['code' => 'REPAIR_PATHWAY', 'label' => 'Repaired the pathway', 'clause' => 'repaired the damaged pathway'],
                ['code' => 'REPLACE_MATERIAL', 'label' => 'Replaced damaged masonry material', 'clause' => 'replaced the damaged masonry material'],
                ['code' => 'SMOOTH_SURFACE', 'label' => 'Smoothed the finished surface', 'clause' => 'smoothed the finished surface'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('PAINTING', 'Painting work', [
                ['code' => 'INSPECT_SURFACE', 'label' => 'Inspected the surface', 'clause' => 'inspected the surface'],
                ['code' => 'PREPARE_SURFACE', 'label' => 'Cleaned and prepared the surface', 'clause' => 'cleaned and prepared the surface'],
                ['code' => 'REMOVE_PAINT', 'label' => 'Removed loose paint', 'clause' => 'removed the loose paint'],
                ['code' => 'APPLY_PRIMER', 'label' => 'Applied primer', 'clause' => 'applied primer'],
                ['code' => 'APPLY_FIRST_COAT', 'label' => 'Applied the first coat', 'clause' => 'applied the first coat of paint'],
                ['code' => 'APPLY_FINAL_COAT', 'label' => 'Applied the final coat', 'clause' => 'applied the final coat of paint'],
                ['code' => 'REPAINT_AREA', 'label' => 'Repainted the damaged area', 'clause' => 'repainted the damaged area'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the work area', 'clause' => 'cleaned the work area'],
            ]),
            $profile('GENERIC', 'work performed', [
                ['code' => 'INSPECT_AREA', 'label' => 'Inspected the affected area', 'clause' => 'inspected the affected area'],
                ['code' => 'CLEAN_AREA', 'label' => 'Cleaned the area', 'clause' => 'cleaned the affected area'],
                ['code' => 'REPAIR_ITEM', 'label' => 'Repaired the item', 'clause' => 'repaired the damaged item'],
                ['code' => 'REPLACE_PART', 'label' => 'Replaced a damaged part', 'clause' => 'replaced the damaged part'],
                ['code' => 'ADJUST_COMPONENT', 'label' => 'Adjusted the component', 'clause' => 'adjusted the affected component'],
                ['code' => 'TEST_WORK', 'label' => 'Tested after the work', 'clause' => 'tested the completed work'],
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
