<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkOrderPreferenceMigrationTest extends TestCase
{
    public function test_legacy_single_preference_is_backfilled_before_the_column_is_removed(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.preference_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('preference_migration');

        try {
            Schema::create('work_order_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
            });
            Schema::create('skills', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
            });
            Schema::create('fmo_personnel', function (Blueprint $table): void {
                $table->ulid('id')->primary();
            });
            Schema::create('work_orders', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('preferred_fmo_personnel_id')->nullable();
                $table->foreign('preferred_fmo_personnel_id')->references('id')->on('fmo_personnel')->restrictOnDelete();
            });

            $personnelId = '01JPERSONNEL000000000000000';
            $workOrderId = '01JWORKORDER00000000000000';
            DB::table('work_order_categories')->insert(['name' => 'Electrical']);
            DB::table('skills')->insert(['name' => 'Electrical']);
            DB::table('fmo_personnel')->insert(['id' => $personnelId]);
            DB::table('work_orders')->insert([
                'id' => $workOrderId,
                'preferred_fmo_personnel_id' => $personnelId,
            ]);

            $migration = require database_path('migrations/2026_10_19_000001_create_work_order_personnel_preferences.php');
            $migration->up();

            $this->assertDatabaseHas('work_order_personnel_preferences', [
                'work_order_id' => $workOrderId,
                'fmo_personnel_id' => $personnelId,
            ], 'preference_migration');
            $this->assertFalse(Schema::hasColumn('work_orders', 'preferred_fmo_personnel_id'));
            $this->assertDatabaseHas('work_order_category_skill', [
                'work_order_category_id' => 1,
                'skill_id' => 1,
            ], 'preference_migration');

            DB::table('work_order_category_skill')->delete();
            $mappingRepair = require database_path('migrations/2026_10_20_000001_backfill_standard_work_order_category_skills.php');
            $mappingRepair->up();
            $mappingRepair->up();
            $this->assertDatabaseCount('work_order_category_skill', 1, 'preference_migration');
            $this->assertDatabaseHas('work_order_category_skill', [
                'work_order_category_id' => 1,
                'skill_id' => 1,
            ], 'preference_migration');
        } finally {
            DB::purge('preference_migration');
            DB::setDefaultConnection($originalConnection);
        }
    }
}
