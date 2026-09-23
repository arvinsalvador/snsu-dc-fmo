<?php

namespace Database\Seeders;

use App\Models\Campus;
use Illuminate\Database\Seeder;

class CampusSeeder extends Seeder
{
    public function run(): void
    {
        Campus::firstOrCreate(['code' => 'DC'], ['name' => 'SNSU Del Carmen Campus', 'short_name' => 'Del Carmen', 'is_active' => true]);
    }
}
