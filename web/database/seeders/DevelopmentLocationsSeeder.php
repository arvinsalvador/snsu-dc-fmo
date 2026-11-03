<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use Illuminate\Database\Seeder;

class DevelopmentLocationsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $campus = Campus::where('code', 'DC')->firstOrFail();
        foreach ($this->buildings() as $buildingCode => $definition) {
            $building = Building::updateOrCreate(
                ['campus_id' => $campus->id, 'code' => $buildingCode],
                ['name' => $definition['name'], 'is_active' => true],
            );
            foreach ($definition['floors'] as $floorOrder => $floorDefinition) {
                $floor = Floor::updateOrCreate(
                    ['building_id' => $building->id, 'name' => $floorDefinition['name']],
                    ['code' => 'F'.($floorOrder + 1), 'display_order' => $floorOrder + 1, 'is_active' => true],
                );
                foreach ($floorDefinition['locations'] as $location) {
                    $this->location($building, $floor, $location);
                }
            }
        }

        $this->outdoorLocations($campus);
    }

    /** @param array{name: string, type?: LocationType} $definition */
    private function location(Building $building, ?Floor $floor, array|string $definition): void
    {
        $name = is_array($definition) ? $definition['name'] : $definition;
        $type = is_array($definition) && isset($definition['type'])
            ? $definition['type']
            : $this->typeFor($name);

        BuildingLocation::updateOrCreate(
            ['building_id' => $building->id, 'floor_id' => $floor?->id, 'name' => $name],
            ['type' => $type, 'is_active' => true],
        );
    }

    private function outdoorLocations(Campus $campus): void
    {
        $anchors = [
            'AgriFisheries Building' => ['Lot A', 'Lot B', 'Agricultural Area'],
            'Admin Building' => ['Main Gate', 'Guard House', 'Campus Grounds'],
        ];
        foreach ($anchors as $buildingName => $locations) {
            $building = Building::where('campus_id', $campus->id)->where('name', $buildingName)->firstOrFail();
            foreach ($locations as $name) {
                BuildingLocation::updateOrCreate(
                    ['building_id' => $building->id, 'floor_id' => null, 'name' => $name],
                    [
                        'type' => LocationType::OutdoorArea,
                        'description' => 'Campus outdoor area attached to the nearest operational location anchor.',
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function typeFor(string $name): LocationType
    {
        return match (true) {
            str_contains($name, 'Comfort Room') => LocationType::ComfortRoom,
            str_contains($name, 'Laboratory'), str_contains($name, 'Lab ') => LocationType::Laboratory,
            str_contains($name, 'Stock Room'), $name === 'Disposal' => LocationType::Storage,
            str_contains($name, 'Office'), in_array($name, ['Registrar', 'Cashier', 'Guidance', 'Clinic', 'USC', 'Supply Office'], true) => LocationType::Office,
            str_starts_with($name, 'AB '), str_starts_with($name, 'AFB '), str_starts_with($name, 'TB '), str_starts_with($name, 'PLDT ') => LocationType::Classroom,
            $name === 'Roof Deck' => LocationType::OutdoorArea,
            default => LocationType::CommonArea,
        };
    }

    /** @return array<string, array{name: string, floors: list<array{name: string, locations: list<array{name: string, type?: LocationType}|string>}>}> */
    private function buildings(): array
    {
        $first = fn (array $locations): array => [['name' => 'First Floor', 'locations' => $locations]];

        return [
            'RIS1' => ['name' => 'RIS 1', 'floors' => $first(['Comfort Room'])],
            'RIS2' => ['name' => 'RIS 2', 'floors' => $first(['Comfort Room'])],
            'CANTEEN' => ['name' => 'Coffee Shop / Canteen', 'floors' => $first([])],
            'TB1' => ['name' => 'Technology Building 1', 'floors' => $first(['TB 101', 'TB 102', 'TB 103', 'TB 104', 'Program Chairs Office'])],
            'TB2' => ['name' => 'Technology Building 2', 'floors' => $first(['TB 106 — Electrical Laboratory', 'TB 107', 'TB 108 — Automotive Laboratory', 'TB 109', 'Faculty Office'])],
            'TB3' => ['name' => 'Technology Building 3', 'floors' => $first(['Disposal', 'FMO Office', 'Supply Office Stock Room', 'FMO Stock Room', 'FMO Staff Room', 'Supply Office', 'Comfort Room'])],
            'TB4' => ['name' => 'Technology Building 4', 'floors' => $first(['Cafeteria', 'Food Innovation Center', 'Comfort Room'])],
            'KALAHI' => ['name' => 'Kalahi Building', 'floors' => $first(['Faculty Office'])],
            'PLDT' => ['name' => 'PLDT Building', 'floors' => $first(['PLDT 1', 'PLDT 2'])],
            'CHED' => ['name' => 'CHED Building', 'floors' => $first(['Computer Laboratory', 'Printing Hub', 'Old Science Laboratory', 'Comfort Room'])],
            'ADMIN' => ['name' => 'Admin Building', 'floors' => [
                ['name' => 'First Floor', 'locations' => ['SAO & Placement', 'Radyo Kabakhawan', 'Registrar', 'SFAU / Assessment', 'Budget & Accounting', 'Cashier', 'Comfort Room']],
                ['name' => 'Second Floor', 'locations' => ['HRMO', 'RIE', 'QUAMS and IZN', 'Guidance']],
                ['name' => 'Third Level', 'locations' => ['Roof Deck']],
            ]],
            'AFB' => ['name' => 'AgriFisheries Building', 'floors' => [
                ['name' => 'First Floor', 'locations' => ['AFB 101', 'AFB 102', 'Comfort Room']],
                ['name' => 'Second Floor', 'locations' => ['AFB 201', 'AFB 202']],
            ]],
            'LRC' => ['name' => 'LRC Building', 'floors' => [
                ['name' => 'First Floor', 'locations' => ['Director for Instruction', 'Campus Director', 'Conference', 'Comfort Room']],
                ['name' => 'Second Floor', 'locations' => ['Library', 'E-Library 1', 'E-Library 2', 'Comfort Room']],
                ['name' => 'Third Floor', 'locations' => ['Left Wing (DOST Temporary)', 'Right Wing', 'Local Governance Resource Section', 'Comfort Room']],
                ['name' => 'Fourth Level', 'locations' => ['Roof Deck']],
            ]],
            'DORM' => ['name' => 'Dormitory Building', 'floors' => [
                ['name' => 'First Floor', 'locations' => ['Room 1', 'Room 2', 'Room 3', 'Room 4', 'Room 5', 'Room 6', 'Comfort Room']],
                ['name' => 'Second Floor', 'locations' => ['Room 7', 'Room 8', 'Room 9', 'Room 10', 'Room 11', 'Room 12', 'Room 13', 'Comfort Room']],
                ['name' => 'Third Level', 'locations' => ['Roof Deck']],
            ]],
            'ACADEMIC' => ['name' => 'Academic Building', 'floors' => [
                ['name' => 'First Floor', 'locations' => ['AB 101', 'AB 102', 'AB 103', 'AB 104', 'Food Lab (AB 105/106)', 'AB 107', 'Clinic', 'NSTP/CWTS', 'Comfort Room']],
                ['name' => 'Second Floor', 'locations' => ['AB 201', 'AB 202', 'AB 203', 'AB 204', 'AB 205', 'AB 206', 'AB 207', 'AB 208', 'USC', 'Culture and Arts', 'Comfort Room']],
            ]],
        ];
    }
}
