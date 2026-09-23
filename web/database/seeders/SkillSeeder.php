<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Electrical', 'Plumbing', 'Carpentry / Woodworking', 'Welding',
            'Masonry', 'Painting', 'Air Conditioning', 'General Maintenance',
            'Fabrication', 'Grounds Maintenance', 'Other',
        ] as $name) {
            Skill::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
