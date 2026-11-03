<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevelopmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Development sample data was skipped outside local/testing.');

            return;
        }

        $this->call([
            AuthorizationSeeder::class,
            SkillSeeder::class,
            CampusSeeder::class,
            WorkOrderCategorySeeder::class,
            RoutineOperationsSeeder::class,
            DevelopmentUsersSeeder::class,
            DevelopmentLocationsSeeder::class,
            HistoricalFmoOperationsSeeder::class,
        ]);
    }
}
