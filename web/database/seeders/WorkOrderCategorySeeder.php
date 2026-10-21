<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\WorkOrderCategory;
use Illuminate\Database\Seeder;

class WorkOrderCategorySeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'General Repair / Maintenance' => ['General Maintenance'],
            'Electrical' => ['Electrical'],
            'Plumbing' => ['Plumbing'],
            'Carpentry / Woodworking' => ['Carpentry / Woodworking'],
            'Welding' => ['Welding'],
            'Fabrication / Craft' => ['Fabrication', 'Welding'],
            'Painting' => ['Painting'],
            'Masonry' => ['Masonry'],
            'Air Conditioning' => ['Air Conditioning', 'Electrical'],
            'Furniture / Fixture' => ['Carpentry / Woodworking', 'General Maintenance'],
            'Grounds / Outdoor Maintenance' => ['Grounds Maintenance'],
            'Other' => [],
        ];

        foreach ($mapping as $name => $skills) {
            $category = WorkOrderCategory::firstOrCreate(['name' => $name], ['is_active' => true, 'display_order' => array_search($name, array_keys($mapping), true)]);
            $category->relevantSkills()->sync(Skill::whereIn('name', $skills)->pluck('id'));
        }
    }
}
