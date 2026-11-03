<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\Skill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use App\Models\WorkOrderCategory;
use App\Models\WorkUpdate;
use App\Services\ReportingService;
use App\Services\WorkOrderPreferenceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentSampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_demo_seed_is_complete_scoped_and_idempotent(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('testing', config('database.connections.'.config('database.default').'.database'));

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(21, User::where('email', 'like', '%@example.test')->count());
        $this->assertSame(9, FmoPersonnel::count());
        $this->assertSame(12, Skill::count());
        $this->assertSame(15, Building::count());
        $this->assertSame(24, Floor::count());
        $this->assertSame(101, BuildingLocation::count());
        $this->assertSame(24, WorkOrder::whereHas('workflowEvents', fn ($query) => $query->where('action', 'HISTORICAL_IMPORT'))->count());
        $this->assertSame(30, WorkOrderAssignment::count());
        $this->assertSame(34, WorkUpdate::count());
        $this->assertSame(2, RoutineTaskTemplate::where('recurrence_config->historical_import', true)->count());
        $this->assertSame(10, RoutineTaskOccurrence::where('resolution_code', 'HISTORICAL_COMPLETED')->count());

        $joey = User::where('email', 'joey.escauso@example.test')->firstOrFail();
        $kier = User::where('email', 'kier.ecle@example.test')->firstOrFail();
        $arvin = User::where('email', 'arvin.salvador@example.test')->firstOrFail();
        $this->assertTrue($joey->hasAllRoles(['Campus Director', 'Requester']));
        $this->assertTrue($kier->hasAllRoles(['Director for Instruction', 'Requester']));
        $this->assertTrue($arvin->hasRole('FMO Head'));
        $this->assertFalse($arvin->hasRole('System Administrator'));

        $ferol = $this->personnel('chimbhaco.ferol@example.test');
        $this->assertSame('Grounds Maintenance', $ferol->skills->firstWhere('pivot.is_primary', true)?->name);
        $this->assertSame(5, $ferol->skills->count());
        $this->assertSame(6, RoutineTaskOccurrence::where('performed_by_personnel_id', $ferol->id)->count());

        $shed = WorkOrder::where('subject', 'Construction of Storage Shed for Hardwood Materials')
            ->whereHas('workflowEvents', fn ($query) => $query->where('action', 'HISTORICAL_IMPORT'))
            ->firstOrFail();
        $this->assertSame(3, $shed->assignments()->count());
        $this->assertSame(5, $shed->updates()->count());
        $this->assertSame('FOR_CONTINUATION', $shed->status->value);
        $this->assertSame($arvin->id, $shed->decided_by);
        $this->assertSame('Historical FMO Operations', $shed->requester->name);
        $this->assertNotSame($shed->decided_by, $shed->requester_id);

        $floria = User::where('email', 'floria.dialde@example.test')->firstOrFail();
        $this->assertSame(2, app(ReportingService::class)->query($floria, [])->count());
        $this->assertSame(0, app(ReportingService::class)->query($floria, [])->where('requester_id', '!=', $floria->id)->count());

        foreach (['kevin.dacera@example.test', 'ronald.dulguime@example.test', 'blas.vicio@example.test'] as $email) {
            $this->assertSame(0, $this->personnel($email)->workOrderAssignments()->count());
        }

        $this->assertLocation('Admin Building', 'Second Floor', 'Guidance');
        $this->assertLocation('Technology Building 2', 'First Floor', 'TB 108 — Automotive Laboratory');
        $this->assertLocation('Admin Building', 'Third Level', 'Roof Deck');
        $this->assertLocation('LRC Building', 'Fourth Level', 'Roof Deck');
        $this->assertLocation('Dormitory Building', 'Third Level', 'Roof Deck');

        $this->assertPreference('Electrical', ['Blas C. Vicio Jr.', 'Jerico C. Nobleza']);
        $this->assertPreference('Masonry', ['Gary R. Pascual', 'Russel F. Curada']);
        $this->assertPreference('Welding', ['Mielven Andamon']);
        $this->assertPreference('Grounds / Outdoor Maintenance', ['Chimbhaco D. Ferol']);
        $this->assertPreference('Transportation / Driver Assistance', ['Elmer O. Toldo']);

        $counts = $this->counts();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($counts, $this->counts());
    }

    private function personnel(string $email): FmoPersonnel
    {
        return FmoPersonnel::with('skills')->whereHas('user', fn ($query) => $query->where('email', $email))->firstOrFail();
    }

    private function assertLocation(string $buildingName, string $floorName, string $locationName): void
    {
        $this->assertTrue(BuildingLocation::where('name', $locationName)
            ->whereHas('building', fn ($query) => $query->where('name', $buildingName))
            ->whereHas('floor', fn ($query) => $query->where('name', $floorName))
            ->exists());
    }

    /** @param list<string> $expected */
    private function assertPreference(string $categoryName, array $expected): void
    {
        $category = WorkOrderCategory::where('name', $categoryName)->firstOrFail();
        $names = app(WorkOrderPreferenceService::class)->eligiblePersonnel($category)->pluck('user.name');
        foreach ($expected as $name) {
            $this->assertTrue($names->contains($name), "Expected {$name} to match {$categoryName}.");
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users' => User::count(),
            'personnel' => FmoPersonnel::count(),
            'skills' => Skill::count(),
            'categories' => WorkOrderCategory::count(),
            'buildings' => Building::count(),
            'floors' => Floor::count(),
            'locations' => BuildingLocation::count(),
            'orders' => WorkOrder::count(),
            'assignments' => WorkOrderAssignment::count(),
            'updates' => WorkUpdate::count(),
            'routine_templates' => RoutineTaskTemplate::count(),
            'routine_occurrences' => RoutineTaskOccurrence::count(),
        ];
    }
}
