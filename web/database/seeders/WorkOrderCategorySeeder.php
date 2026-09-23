<?php

namespace Database\Seeders;

use App\Models\WorkOrderCategory;
use Illuminate\Database\Seeder;

class WorkOrderCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['General Repair / Maintenance', 'Electrical', 'Plumbing', 'Carpentry / Woodworking', 'Welding', 'Fabrication / Craft', 'Painting', 'Masonry', 'Air Conditioning', 'Furniture / Fixture', 'Grounds / Outdoor Maintenance', 'Other'] as $i => $name) {
            WorkOrderCategory::firstOrCreate(['name' => $name], ['is_active' => true, 'display_order' => $i]);
        }
    }
}
