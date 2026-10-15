<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->string('inspection_asset_scope', 30)->default('TYPE_LOCATION')->after('asset_type_id');
            $table->boolean('customize_asset_assignments')->default(false)->after('inspection_asset_scope');
        });
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->foreignId('campus_id')->nullable()->change();
        });

        Schema::create('routine_task_template_assets', function (Blueprint $table): void {
            $table->foreignUlid('routine_task_template_id')->constrained('routine_task_templates', 'id', 'rt_template_asset_template_fk')->cascadeOnDelete();
            $table->foreignUlid('inspectable_asset_id')->constrained('inspectable_assets', 'id', 'rt_template_asset_asset_fk')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['routine_task_template_id', 'inspectable_asset_id'], 'rt_template_asset_primary');
        });

        Schema::create('inspection_template_asset_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('routine_task_template_id')->constrained('routine_task_templates', 'id', 'ita_template_fk')->cascadeOnDelete();
            $table->foreignUlid('inspectable_asset_id')->constrained('inspectable_assets', 'id', 'ita_asset_fk')->restrictOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel', 'id', 'ita_personnel_fk')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users', 'id', 'ita_assigned_by_fk')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users', 'id', 'ita_unassigned_by_fk')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['routine_task_template_id', 'inspectable_asset_id', 'fmo_personnel_id', 'active_marker'], 'ita_active_unique');
        });

        Schema::table('inspection_items', function (Blueprint $table): void {
            $table->json('checklist_snapshot')->nullable()->after('recommended_action');
            $table->json('asset_context')->nullable()->after('checklist_snapshot');
        });

        Schema::create('inspection_item_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspection_item_id')->constrained('inspection_items', 'id', 'iia_item_fk')->cascadeOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel', 'id', 'iia_personnel_fk')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users', 'id', 'iia_assigned_by_fk')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users', 'id', 'iia_unassigned_by_fk')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['inspection_item_id', 'fmo_personnel_id', 'active_marker'], 'iia_active_unique');
            $table->index(['fmo_personnel_id', 'unassigned_at'], 'iia_personnel_active_idx');
        });

        Schema::create('asset_action_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_action_request_id')->constrained('asset_action_requests', 'id', 'aaa_action_fk')->cascadeOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel', 'id', 'aaa_personnel_fk')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users', 'id', 'aaa_assigned_by_fk')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by')->nullable()->constrained('users', 'id', 'aaa_unassigned_by_fk')->restrictOnDelete();
            $table->text('change_reason')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['asset_action_request_id', 'fmo_personnel_id', 'active_marker'], 'aaa_active_unique');
        });

        $existingItems = DB::table('inspection_items as item')
            ->join('inspection_runs as run', 'run.id', '=', 'item.inspection_run_id')
            ->join('routine_task_occurrences as occurrence', 'occurrence.id', '=', 'run.routine_task_occurrence_id')
            ->join('routine_task_templates as template', 'template.id', '=', 'run.routine_task_template_id')
            ->join('routine_task_occurrence_assignees as assignee', 'assignee.routine_task_occurrence_id', '=', 'occurrence.id')
            ->select('item.id as inspection_item_id', 'assignee.fmo_personnel_id', 'template.created_by', 'item.created_at', 'item.updated_at')
            ->get();
        foreach ($existingItems as $assignment) {
            DB::table('inspection_item_assignments')->insert([
                'id' => (string) Str::ulid(),
                'inspection_item_id' => $assignment->inspection_item_id,
                'fmo_personnel_id' => $assignment->fmo_personnel_id,
                'assigned_by' => $assignment->created_by,
                'assigned_at' => $assignment->created_at,
                'active_marker' => 1,
                'created_at' => $assignment->created_at,
                'updated_at' => $assignment->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_action_assignments');
        Schema::dropIfExists('inspection_item_assignments');
        Schema::table('inspection_items', function (Blueprint $table): void {
            $table->dropColumn(['checklist_snapshot', 'asset_context']);
        });
        Schema::dropIfExists('inspection_template_asset_assignments');
        Schema::dropIfExists('routine_task_template_assets');
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->dropColumn(['inspection_asset_scope', 'customize_asset_assignments']);
        });
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->foreignId('campus_id')->nullable(false)->change();
        });
    }
};
