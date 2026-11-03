<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\RoutineFrequency;
use App\Enums\RoutineOccurrenceStatus;
use App\Enums\RoutineTaskType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkSessionOutcome;
use App\Enums\WorkUpdateType;
use App\Http\Controllers\WorkOrders\WorkOrderController;
use App\Models\Building;
use App\Models\BuildingLocation;
use App\Models\Campus;
use App\Models\Floor;
use App\Models\FmoPersonnel;
use App\Models\RoutineTaskOccurrence;
use App\Models\RoutineTaskTemplate;
use App\Models\RoutineTaskTemplateAssignment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAssignment;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderWorkflowEvent;
use App\Models\WorkSession;
use App\Models\WorkUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HistoricalFmoOperationsSeeder extends Seeder
{
    private User $director;

    private User $historicalRequester;

    private Campus $campus;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->director = User::where('email', 'arvin.salvador@example.test')->firstOrFail();
        $this->historicalRequester = User::firstOrCreate(
            ['email' => 'historical.operations@example.test'],
            ['name' => 'Historical FMO Operations', 'user_type' => 'staff', 'password' => Str::random(64)],
        );
        $this->historicalRequester->forceFill([
            'status' => AccountStatus::Approved,
            'email_verified_at' => $this->historicalRequester->email_verified_at ?? now(),
            'approved_at' => $this->historicalRequester->approved_at ?? now(),
        ])->save();
        $this->historicalRequester->syncRoles([]);
        $this->campus = Campus::where('code', 'DC')->firstOrFail();

        foreach ($this->workOrders() as $definition) {
            DB::transaction(fn () => $this->seedWorkOrder($definition));
        }

        DB::transaction(fn () => $this->seedRoutineHistory());
    }

    /** @param array<string, mixed> $definition */
    private function seedWorkOrder(array $definition): void
    {
        $requester = isset($definition['requester'])
            ? User::where('email', $definition['requester'])->firstOrFail()
            : $this->historicalRequester;
        $category = WorkOrderCategory::where('name', $definition['category'])->firstOrFail();
        $location = $this->resolveLocation($definition);
        $status = $definition['status'] ?? WorkOrderStatus::Completed;
        $firstDate = CarbonImmutable::parse($definition['date'], 'Asia/Manila');
        $lastDate = collect($definition['updates'])->max('date');
        $lastAt = CarbonImmutable::parse($lastDate.' 17:00:00', 'Asia/Manila');
        $submittedAt = $firstDate->setTime(8, 0);
        $existing = WorkOrder::query()
            ->where('requester_id', $requester->id)
            ->where('campus_id', $this->campus->id)
            ->where('subject', $definition['subject'])
            ->where('submitted_at', $submittedAt)
            ->whereHas('workflowEvents', fn ($query) => $query
                ->where('action', 'HISTORICAL_IMPORT')
                ->where('internal_note', $this->historicalImportNote($definition)))
            ->first();

        $attributes = [
            'requester_id' => $requester->id,
            'work_order_category_id' => $category->id,
            'campus_id' => $this->campus->id,
            'building_id' => $location['building']->id,
            'floor_id' => $location['floor']?->id,
            'building_location_id' => $location['location']?->id,
            'subject' => $definition['subject'],
            'description' => $definition['description']
                .(isset($definition['historical_location'])
                    ? "\n\nLocation noted in source: {$definition['historical_location']}"
                    : ''),
            'urgency' => 'NORMAL',
            'status' => $status,
            'submitted_at' => $submittedAt,
            'recommendation' => 'APPROVE',
            'decided_by' => $this->director->id,
            'decided_at' => $firstDate->setTime(9, 0),
            'execution_cycle' => 1,
        ];
        if ($status === WorkOrderStatus::Completed) {
            $attributes += [
                'completion_summary' => 'Historical work completed as documented in the FMO logbook.',
                'work_performed_summary' => collect($definition['updates'])->pluck('description')->join(' '),
                'non_photo_reason' => 'Historical logbook import; digital evidence was not available.',
                'completion_submitted_by' => $this->director->id,
                'completion_submitted_at' => $lastAt,
                'verified_by' => $this->director->id,
                'verified_at' => $lastAt->addHour(),
                'verification_note' => 'Imported from the historical FMO logbook for development reference.',
            ];
        } else {
            $attributes += [
                'completion_summary' => null, 'work_performed_summary' => null, 'non_photo_reason' => null,
                'completion_submitted_by' => null, 'completion_submitted_at' => null,
                'verified_by' => null, 'verified_at' => null, 'verification_note' => null,
            ];
        }

        $workOrder = $existing ?? new WorkOrder(['work_order_number' => app(WorkOrderController::class)->number()]);
        $workOrder->fill($attributes)->save();

        $people = collect($definition['updates'])->pluck('personnel')->unique()->values();
        $assignments = [];
        foreach ($people as $email) {
            $personnel = $this->personnel($email);
            $assignment = WorkOrderAssignment::firstOrNew([
                'work_order_id' => $workOrder->id,
                'fmo_personnel_id' => $personnel->id,
            ]);
            $assignment->fill([
                'assigned_by_user_id' => $this->director->id,
                'assigned_at' => $firstDate->setTime(9, 30),
                'assignment_note' => 'Historical performer identified in the source logbook.',
                'active_marker' => $status === WorkOrderStatus::Completed ? null : 1,
                'unassigned_at' => $status === WorkOrderStatus::Completed ? $lastAt->addHour() : null,
                'unassigned_by_user_id' => $status === WorkOrderStatus::Completed ? $this->director->id : null,
                'unassignment_reason' => $status === WorkOrderStatus::Completed ? 'Historical work completed.' : null,
            ])->save();
            $assignments[$email] = $assignment;
        }

        foreach ($definition['updates'] as $index => $update) {
            $personnel = $this->personnel($update['personnel']);
            $recordedAt = CarbonImmutable::parse($update['date'].' 14:00:00', 'Asia/Manila')->addMinutes($index);
            $session = WorkSession::firstOrNew([
                'work_order_id' => $workOrder->id,
                'fmo_personnel_id' => $personnel->id,
                'started_at' => $recordedAt->subHour(),
            ]);
            $session->fill([
                'work_order_assignment_id' => $assignments[$update['personnel']]->id,
                'started_by_user_id' => $personnel->user_id,
                'ended_at' => $recordedAt->addHour(),
                'end_outcome' => $status === WorkOrderStatus::Completed && $update['date'] === $lastDate
                    ? WorkSessionOutcome::WorkOrderCompletionSubmitted
                    : WorkSessionOutcome::ContributionComplete,
                'session_summary' => $update['description'],
                'active_marker' => null,
            ])->save();

            WorkUpdate::updateOrCreate(
                [
                    'work_order_id' => $workOrder->id,
                    'fmo_personnel_id' => $personnel->id,
                    'recorded_at' => $recordedAt,
                ],
                [
                    'work_session_id' => $session->id,
                    'created_by_user_id' => $personnel->user_id,
                    'type' => $status === WorkOrderStatus::Completed && $update['date'] === $lastDate
                        ? WorkUpdateType::Completion
                        : WorkUpdateType::Progress,
                    'description' => $update['description'],
                    'requester_summary' => $update['description'],
                    'execution_cycle' => 1,
                ],
            );
        }

        $this->workflow($workOrder, $definition, $firstDate, $lastAt, $status);
    }

    /** @param array<string, mixed> $definition */
    private function workflow(WorkOrder $workOrder, array $definition, CarbonImmutable $firstDate, CarbonImmutable $lastAt, WorkOrderStatus $status): void
    {
        $actor = isset($definition['requester'])
            ? User::where('email', $definition['requester'])->firstOrFail()
            : $this->director;
        $events = [
            ['HISTORICAL_IMPORT', null, WorkOrderStatus::Approved->value, $actor->id, $firstDate->setTime(8, 0)],
            ['ASSIGN', WorkOrderStatus::Approved->value, WorkOrderStatus::Assigned->value, $this->director->id, $firstDate->setTime(9, 30)],
            ['START_WORK', WorkOrderStatus::Assigned->value, WorkOrderStatus::InProgress->value, $this->director->id, $firstDate->setTime(13, 0)],
        ];
        $events[] = $status === WorkOrderStatus::Completed
            ? ['SUBMIT_COMPLETION', WorkOrderStatus::InProgress->value, WorkOrderStatus::ForVerification->value, $this->director->id, $lastAt]
            : ['END_FOR_CONTINUATION', WorkOrderStatus::InProgress->value, WorkOrderStatus::ForContinuation->value, $this->director->id, $lastAt];
        if ($status === WorkOrderStatus::Completed) {
            $events[] = ['VERIFY_COMPLETION', WorkOrderStatus::ForVerification->value, WorkOrderStatus::Completed->value, $this->director->id, $lastAt->addHour()];
        }

        foreach ($events as [$action, $from, $to, $actorId, $at]) {
            WorkOrderWorkflowEvent::updateOrCreate(
                ['work_order_id' => $workOrder->id, 'action' => $action, 'created_at' => $at],
                [
                    'actor_id' => $actorId,
                    'from_status' => $from,
                    'to_status' => $to,
                    'internal_note' => $action === 'HISTORICAL_IMPORT'
                        ? $this->historicalImportNote($definition)
                        : 'Development historical import from the manual FMO logbook.',
                ],
            );
        }
    }

    /** @param array<string, mixed> $definition */
    private function historicalImportNote(array $definition): string
    {
        return "Development historical import from the manual FMO logbook. Import key: {$definition['key']}";
    }

    /** @param array<string, mixed> $definition
     * @return array{building: Building, floor: ?Floor, location: ?BuildingLocation}
     */
    private function resolveLocation(array $definition): array
    {
        $building = Building::where('campus_id', $this->campus->id)->where('name', $definition['building'])->firstOrFail();
        $floor = isset($definition['floor'])
            ? Floor::where('building_id', $building->id)->where('name', $definition['floor'])->firstOrFail()
            : null;
        $location = isset($definition['location'])
            ? BuildingLocation::where('building_id', $building->id)->where('name', $definition['location'])
                ->when($floor, fn ($query) => $query->where('floor_id', $floor->id))
                ->when(! $floor, fn ($query) => $query->whereNull('floor_id'))
                ->firstOrFail()
            : null;

        return compact('building', 'floor', 'location');
    }

    private function personnel(string $email): FmoPersonnel
    {
        return FmoPersonnel::whereHas('user', fn ($query) => $query->where('email', $email))->firstOrFail();
    }

    private function seedRoutineHistory(): void
    {
        $sets = [
            [
                'title' => 'Historical Campus Grounds Maintenance — Ferol',
                'personnel' => 'chimbhaco.ferol@example.test',
                'records' => [
                    ['HIST-20260518', '2026-05-18', '2026-05-22', 'Performed grass cutting and assisted with wiring, welding, masonry, and painting activities.'],
                    ['HIST-20260613', '2026-06-13', '2026-06-17', 'Performed grass cutting and assisted with welding, wiring, masonry, and painting activities.'],
                    ['HIST-20260724', '2026-07-24', '2026-07-31', 'Performed grass cutting and assisted with general maintenance activities, including welding, wiring, masonry, and painting.'],
                    ['HIST-20260817', '2026-08-17', '2026-08-21', 'Performed grass cutting and assisted with welding, wiring, masonry, and painting activities.'],
                    ['HIST-20260928', '2026-09-28', '2026-09-30', 'Performed grass cutting and assisted with welding, wiring, masonry, and painting activities.'],
                    ['HIST-20261019', '2026-10-19', '2026-10-20', 'Performed grass cutting and assisted with welding, wiring, masonry, and painting activities.'],
                ],
            ],
            [
                'title' => 'Historical Supplemental Grounds Maintenance',
                'records' => [
                    ['MIEL-20260506', '2026-05-06', '2026-05-06', 'Cleared tall grass in the Lot A area.', 'mielven.andamon@example.test'],
                    ['RUSS-20260604', '2026-06-04', '2026-06-04', 'Cleared tall grass in the Lot B area.', 'russel.curada@example.test'],
                    ['RUSS-20260722', '2026-07-22', '2026-07-22', 'Cleared tall grass in the Lot B area.', 'russel.curada@example.test'],
                    ['MIEL-20260924', '2026-09-24', '2026-09-24', 'Cleaned the surroundings of the Science Laboratory; the exact building was not forced during import.', 'mielven.andamon@example.test'],
                ],
            ],
        ];

        foreach ($sets as $set) {
            $template = RoutineTaskTemplate::updateOrCreate(
                ['title' => $set['title']],
                [
                    'description' => 'Development-only history imported from manual FMO logbooks.',
                    'task_type' => RoutineTaskType::General,
                    'frequency' => RoutineFrequency::Weekly,
                    'recurrence_config' => ['historical_import' => true],
                    'campus_id' => $this->campus->id,
                    'exact_placement' => 'Campus grounds',
                    'start_date' => collect($set['records'])->min(fn (array $record) => $record[1]),
                    'end_date' => collect($set['records'])->max(fn (array $record) => $record[2]),
                    'due_time' => '17:00:00',
                    'evidence_required' => false,
                    'requires_review' => false,
                    'is_active' => false,
                    'created_by' => $this->director->id,
                ],
            );

            foreach ($set['records'] as $record) {
                [$period, $start, $end, $summary] = $record;
                $personnel = $this->personnel($record[4] ?? $set['personnel']);
                $occurrence = RoutineTaskOccurrence::updateOrCreate(
                    ['routine_task_template_id' => $template->id, 'period_key' => $period],
                    [
                        'expected_date' => $start,
                        'due_at' => CarbonImmutable::parse($end.' 17:00:00', 'Asia/Manila'),
                        'status' => RoutineOccurrenceStatus::Completed,
                        'started_at' => CarbonImmutable::parse($start.' 08:00:00', 'Asia/Manila'),
                        'completed_at' => CarbonImmutable::parse($end.' 17:00:00', 'Asia/Manila'),
                        'performed_by_personnel_id' => $personnel->id,
                        'performed_by_user_id' => $personnel->user_id,
                        'generated_summary' => $summary,
                        'additional_remarks' => 'Historical development import; exact daily detail was not inferred.',
                        'resolution_code' => 'HISTORICAL_COMPLETED',
                    ],
                );
                $occurrence->assignees()->syncWithoutDetaching([$personnel->id]);
                RoutineTaskTemplateAssignment::updateOrCreate(
                    ['routine_task_template_id' => $template->id, 'fmo_personnel_id' => $personnel->id],
                    [
                        'assigned_by' => $this->director->id,
                        'assigned_at' => CarbonImmutable::parse($start.' 08:00:00', 'Asia/Manila'),
                        'unassigned_at' => CarbonImmutable::parse($end.' 17:00:00', 'Asia/Manila'),
                        'active_marker' => null,
                    ],
                );
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function workOrders(): array
    {
        $mielven = 'mielven.andamon@example.test';
        $russel = 'russel.curada@example.test';
        $gary = 'gary.pascual@example.test';
        $jerico = 'jerico.nobleza@example.test';
        $update = fn (string $date, string $personnel, string $description): array => compact('date', 'personnel', 'description');

        return [
            ['key' => 'cafeteria-road-concreting-2026', 'date' => '2026-05-11', 'subject' => 'Concreting of Access Road and Flooring in Front of Cafeteria', 'description' => 'Prepared, laid out, and concreted the access road and flooring in front of the Cafeteria.', 'category' => 'Masonry', 'building' => 'Technology Building 4', 'floor' => 'First Floor', 'location' => 'Cafeteria', 'status' => WorkOrderStatus::ForContinuation, 'updates' => [
                $update('2026-05-11', $gary, 'Prepared and laid out the area for concreting the road in front of the Cafeteria.'),
                $update('2026-05-19', $gary, 'Prepared and concreted the road and flooring in front of the Cafeteria.'),
                $update('2026-05-19', $russel, 'Assisted with concreting the access road near the Cafeteria.'),
                $update('2026-07-22', $gary, 'Continued concreting the road in front of the Cafeteria.'),
            ]],
            ['key' => 'automotive-lab-fire-extinguisher-20260611', 'date' => '2026-06-11', 'requester' => 'segundino.mahinay@example.test', 'subject' => 'Fire Extinguisher Installation at Automotive Laboratory', 'description' => 'Installed a fire extinguisher at the Automotive Laboratory.', 'category' => 'General Repair / Maintenance', 'building' => 'Technology Building 2', 'floor' => 'First Floor', 'location' => 'TB 108 — Automotive Laboratory', 'updates' => [$update('2026-06-11', $mielven, 'Installed the fire extinguisher at the Automotive Laboratory.')]],
            ['key' => 'cao-ac-frame-20260612', 'date' => '2026-06-12', 'requester' => 'jessa.pande@example.test', 'subject' => 'Fabrication of Air-Conditioning Unit Frame for CAO Office', 'description' => 'Fabricated an air-conditioning unit frame for the CAO Office.', 'category' => 'Fabrication / Craft', 'building' => 'Admin Building', 'historical_location' => 'CAO Office (building not identified in source)', 'updates' => [$update('2026-06-12', $mielven, 'Fabricated the air-conditioning unit frame requested for the CAO Office.')]],
            ['key' => 'agricultural-animal-pens-20260714', 'date' => '2026-07-14', 'requester' => 'ian.paler@example.test', 'subject' => 'Repair of Animal Pens in Agricultural Area', 'description' => 'Repaired animal pens in the agricultural area.', 'category' => 'General Repair / Maintenance', 'building' => 'AgriFisheries Building', 'location' => 'Agricultural Area', 'updates' => [$update('2026-07-14', $mielven, 'Repaired the animal pens in the agricultural area.')]],
            ['key' => 'window-glass-supports-20260722', 'date' => '2026-07-22', 'subject' => 'Installation of Supports for Window Glass', 'description' => 'Installed additional supports and brackets to secure window glass.', 'category' => 'Fabrication / Craft', 'building' => 'Admin Building', 'historical_location' => 'Building and room not identified in source', 'updates' => [$update('2026-07-22', $mielven, 'Installed additional supports and brackets to secure the window glass.')]],
            ['key' => 'lot-a-perimeter-fence-20260810', 'date' => '2026-08-10', 'subject' => 'Repair of Perimeter Fence in Lot A', 'description' => 'Repaired the perimeter fence in the Lot A area.', 'category' => 'General Repair / Maintenance', 'building' => 'AgriFisheries Building', 'location' => 'Lot A', 'updates' => [$update('2026-08-10', $mielven, 'Repaired the perimeter fence in the Lot A area.')]],
            ['key' => 'nutrition-month-structure-20260811', 'date' => '2026-08-11', 'subject' => 'Construction of Nutrition Month Activity Structure', 'description' => 'Constructed a small structure for the Nutrition Month activity.', 'category' => 'Carpentry / Woodworking', 'building' => 'Admin Building', 'location' => 'Campus Grounds', 'updates' => [
                $update('2026-08-11', $mielven, 'Constructed the activity structure and fabricated its supports.'),
                $update('2026-08-11', $russel, 'Assisted with carpentry and construction of the Nutrition Month structure.'),
            ]],
            ['key' => 'faculty-office-ac-holder-20260812', 'date' => '2026-08-12', 'requester' => 'edgar.sulapas@example.test', 'subject' => 'Fabrication of Air-Conditioning Unit Holder for Faculty Office', 'description' => 'Fabricated an air-conditioning unit holder for the Faculty Office.', 'category' => 'Fabrication / Craft', 'building' => 'Admin Building', 'historical_location' => 'Faculty Office (building not identified in source)', 'updates' => [$update('2026-08-12', $mielven, 'Fabricated the air-conditioning unit holder for the Faculty Office.')]],
            ['key' => 'guidance-ac-holder-20260903', 'date' => '2026-09-03', 'requester' => 'floria.dialde@example.test', 'subject' => 'Fabrication of Air-Conditioning Unit Holder for Guidance', 'description' => 'Fabricated an air-conditioning unit holder for the Guidance Office.', 'category' => 'Fabrication / Craft', 'building' => 'Admin Building', 'floor' => 'Second Floor', 'location' => 'Guidance', 'updates' => [$update('2026-09-03', $mielven, 'Fabricated the air-conditioning unit holder for the Guidance Office.')]],
            ['key' => 'agricultural-perimeter-fence-20260921', 'date' => '2026-09-21', 'subject' => 'Repair of Agricultural Area Perimeter Fence', 'description' => 'Repaired the perimeter fence in the agricultural area.', 'category' => 'General Repair / Maintenance', 'building' => 'AgriFisheries Building', 'location' => 'Agricultural Area', 'updates' => [
                $update('2026-09-21', $mielven, 'Repaired damaged portions of the agricultural area perimeter fence.'),
                $update('2026-09-21', $russel, 'Assisted with the agricultural area perimeter fence repair.'),
            ]],
            ['key' => 'metal-barrier-painting-20261005', 'date' => '2026-10-05', 'requester' => 'rogerick.arcaya@example.test', 'subject' => 'Repainting of Metal Barrier', 'description' => 'Repainted the metal barrier.', 'category' => 'Painting', 'building' => 'Admin Building', 'historical_location' => 'Metal barrier (exact campus location not identified)', 'updates' => [$update('2026-10-05', $mielven, 'Prepared and repainted the metal barrier.')]],
            ['key' => 'main-gate-skim-coating-20261005', 'date' => '2026-10-05', 'subject' => 'Skim Coating of Main Gate Wall', 'description' => 'Applied skim coating to the Main Gate wall in preparation for the school donation and landmark area.', 'category' => 'Masonry', 'building' => 'Admin Building', 'location' => 'Main Gate', 'updates' => [$update('2026-10-05', $russel, 'Applied skim coating to the Main Gate wall.')]],
            ['key' => 'guidance-ac-window-frame-20260624', 'date' => '2026-06-24', 'requester' => 'floria.dialde@example.test', 'subject' => 'Installation of Air-Conditioning Window Frame at Guidance', 'description' => 'Installed a window frame for the air-conditioning unit at the Guidance Office.', 'category' => 'Carpentry / Woodworking', 'building' => 'Admin Building', 'floor' => 'Second Floor', 'location' => 'Guidance', 'updates' => [$update('2026-06-24', $gary, 'Installed the air-conditioning window frame at the Guidance Office.')]],
            ['key' => 'quams-ac-window-frame-20260625', 'date' => '2026-06-25', 'requester' => 'rex.legaspi@example.test', 'subject' => 'Installation of Air-Conditioning Window Frame at QUAMS', 'description' => 'Installed a window frame for the air-conditioning unit at the QUAMS Office.', 'category' => 'Carpentry / Woodworking', 'building' => 'Admin Building', 'floor' => 'Second Floor', 'location' => 'QUAMS and IZN', 'updates' => [$update('2026-06-25', $gary, 'Installed the air-conditioning window frame at the QUAMS Office.')]],
            ['key' => 'quams-door-knob-20260714', 'date' => '2026-07-14', 'requester' => 'rex.legaspi@example.test', 'subject' => 'Repair of Door Knob at QUAMS Office', 'description' => 'Repaired the door knob at the QUAMS Office.', 'category' => 'General Repair / Maintenance', 'building' => 'Admin Building', 'floor' => 'Second Floor', 'location' => 'QUAMS and IZN', 'updates' => [$update('2026-07-14', $gary, 'Repaired the door knob at the QUAMS Office.')]],
            ['key' => 'fic-table-installation-20260818', 'date' => '2026-08-18', 'requester' => 'bettie.barcinilla@example.test', 'subject' => 'Table Installation at Food Innovation Center', 'description' => 'Installed a table at the Food Innovation Center.', 'category' => 'Carpentry / Woodworking', 'building' => 'Technology Building 4', 'floor' => 'First Floor', 'location' => 'Food Innovation Center', 'updates' => [$update('2026-08-18', $gary, 'Installed the table at the Food Innovation Center.')]],
            ['key' => 'main-gate-road-layout-20260819', 'date' => '2026-08-19', 'subject' => 'Layout for Main Gate Road and Flooring', 'description' => 'Prepared and laid out the area for concreting the road and flooring at the Main Gate.', 'category' => 'Masonry', 'building' => 'Admin Building', 'location' => 'Main Gate', 'updates' => [$update('2026-08-19', $gary, 'Prepared and laid out the Main Gate road and flooring area for concreting.')]],
            ['key' => 'dormitory-first-floor-plumbing-20260909', 'date' => '2026-09-09', 'subject' => 'Repair of Dormitory Comfort Room Plumbing', 'description' => 'Repaired the plumbing and drainage sink in the first-floor Comfort Room of the Dormitory Building.', 'category' => 'Plumbing', 'building' => 'Dormitory Building', 'floor' => 'First Floor', 'location' => 'Comfort Room', 'updates' => [$update('2026-09-09', $gary, 'Repaired the plumbing fixtures and drainage sink in the first-floor Comfort Room.')]],
            ['key' => 'academic-second-floor-plumbing-20260916', 'date' => '2026-09-16', 'subject' => 'Repair of Academic Building Plumbing Fixtures', 'description' => 'Repaired plumbing fixtures at the rear portion of the second floor of the Academic Building.', 'category' => 'Plumbing', 'building' => 'Academic Building', 'floor' => 'Second Floor', 'historical_location' => 'Rear portion of the second floor', 'updates' => [$update('2026-09-16', $gary, 'Repaired plumbing fixtures at the rear portion of the second floor.')]],
            ['key' => 'guard-house-window-modification-2026', 'date' => '2026-09-30', 'subject' => 'Modification of Guard House Window for Signage', 'description' => 'Modified the Guard House window for installation of school donation signage.', 'category' => 'Carpentry / Woodworking', 'building' => 'Admin Building', 'location' => 'Guard House', 'updates' => [
                $update('2026-09-30', $jerico, 'Assisted with masonry work for the Guard House window modification.'),
                $update('2026-10-01', $gary, 'Modified the Guard House window for installation of school donation signage.'),
            ]],
            ['key' => 'cafeteria-wall-fan-cleaning-20260805', 'date' => '2026-08-05', 'subject' => 'Cleaning of Cafeteria Wall Fan', 'description' => 'Cleaned the wall fan at the Cafeteria.', 'category' => 'General Repair / Maintenance', 'building' => 'Technology Building 4', 'floor' => 'First Floor', 'location' => 'Cafeteria', 'updates' => [$update('2026-08-05', $jerico, 'Cleaned and checked the Cafeteria wall fan.')]],
            ['key' => 'outlet-no-power-inspection-20260916', 'date' => '2026-09-16', 'subject' => 'Inspection of Electrical Outlet Without Power', 'description' => 'Inspected an electrical outlet reported to have no power supply. No repair result was inferred from the source.', 'category' => 'Electrical', 'building' => 'Admin Building', 'historical_location' => 'Exact building and room not identified in source', 'updates' => [$update('2026-09-16', $jerico, 'Inspected the electrical outlet and checked the reported lack of power supply.')]],
            ['key' => 'electrical-lab-outlet-20261005', 'date' => '2026-10-05', 'subject' => 'Electrical Outlet Installation or Repair at Electrical Laboratory', 'description' => 'Performed electrical outlet installation or repair in the Electrical Laboratory.', 'category' => 'Electrical', 'building' => 'Technology Building 2', 'floor' => 'First Floor', 'location' => 'TB 106 — Electrical Laboratory', 'updates' => [$update('2026-10-05', $jerico, 'Performed electrical outlet installation or repair in the Electrical Laboratory.')]],
            ['key' => 'hardwood-storage-shed-2026', 'date' => '2026-09-07', 'subject' => 'Construction of Storage Shed for Hardwood Materials', 'description' => 'Constructed and continued work on a storage shed for hardwood materials.', 'category' => 'Carpentry / Woodworking', 'building' => 'Technology Building 3', 'historical_location' => 'Hardwood storage area (exact room not identified)', 'status' => WorkOrderStatus::ForContinuation, 'updates' => [
                $update('2026-09-07', $russel, 'Assisted in constructing the storage shed for hardwood materials.'),
                $update('2026-10-06', $gary, 'Continued construction of the storage shed for hardwood materials.'),
                $update('2026-10-30', $russel, 'Continued construction of the storage shed for hardwood materials.'),
                $update('2026-11-03', $gary, 'Continued construction work on the hardwood storage shed.'),
                $update('2026-11-03', $jerico, 'Assisted with carpentry work for the continued hardwood storage shed construction.'),
            ]],
        ];
    }
}
