<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspectable_assets', function (Blueprint $table): void {
            $table->string('asset_class', 30)->default('EQUIPMENT')->after('asset_type_id')->index();
            $table->foreignUlid('parent_asset_id')->nullable()->after('asset_class')->constrained('inspectable_assets')->restrictOnDelete();
            $table->index(['parent_asset_id', 'lifecycle_status'], 'assets_parent_lifecycle_index');
        });

        Schema::create('preventive_maintenance_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('maintenance_type', 40)->default('PREVENTIVE');
            $table->string('frequency_type', 20);
            $table->unsignedInteger('interval_value')->default(1);
            $table->json('recurrence_config')->nullable();
            $table->json('checklist_template');
            $table->json('reading_template')->nullable();
            $table->json('recommended_skill_ids')->nullable();
            $table->boolean('evidence_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('template_id')->nullable()->constrained('preventive_maintenance_templates')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('maintenance_type', 40)->default('PREVENTIVE')->index();
            $table->string('frequency_type', 20)->index();
            $table->unsignedInteger('interval_value')->default(1);
            $table->json('recurrence_config')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('available_time')->default('08:00:00');
            $table->time('due_time')->default('17:00:00');
            $table->json('checklist_template');
            $table->json('reading_template')->nullable();
            $table->boolean('evidence_required')->default(false);
            $table->boolean('verification_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['inspectable_asset_id', 'is_active'], 'pm_plans_asset_active_index');
        });

        Schema::create('preventive_maintenance_plan_assignments', function (Blueprint $table): void {
            $table->ulid('preventive_maintenance_plan_id');
            $table->foreign('preventive_maintenance_plan_id', 'pm_plan_assignment_plan_fk')->references('id')->on('preventive_maintenance_plans')->cascadeOnDelete();
            $table->ulid('fmo_personnel_id');
            $table->foreign('fmo_personnel_id', 'pm_plan_assignment_personnel_fk')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['preventive_maintenance_plan_id', 'fmo_personnel_id', 'active_marker'], 'pm_plan_active_assignment_unique');
        });

        Schema::create('preventive_maintenance_occurrences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('preventive_maintenance_plan_id');
            $table->foreign('preventive_maintenance_plan_id', 'pm_occurrence_plan_fk')->references('id')->on('preventive_maintenance_plans')->restrictOnDelete();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->string('period_key', 30);
            $table->date('scheduled_period_start');
            $table->date('scheduled_period_end');
            $table->timestamp('available_at')->index();
            $table->timestamp('due_at')->index();
            $table->string('status', 25)->default('UPCOMING')->index();
            $table->string('plan_name_snapshot');
            $table->string('maintenance_type_snapshot', 40);
            $table->json('checklist_snapshot');
            $table->json('reading_snapshot')->nullable();
            $table->boolean('evidence_required_snapshot')->default(false);
            $table->boolean('verification_required_snapshot')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('verification_note')->nullable();
            $table->timestamp('due_notice_sent_at')->nullable();
            $table->timestamp('overdue_notice_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['preventive_maintenance_plan_id', 'period_key'], 'pm_occurrence_period_unique');
            $table->index(['inspectable_asset_id', 'status'], 'pm_occurrence_asset_status_index');
        });

        Schema::create('preventive_maintenance_occurrence_assignments', function (Blueprint $table): void {
            $table->ulid('preventive_maintenance_occurrence_id');
            $table->foreign('preventive_maintenance_occurrence_id', 'pm_occ_assignment_occurrence_fk')->references('id')->on('preventive_maintenance_occurrences')->cascadeOnDelete();
            $table->ulid('fmo_personnel_id');
            $table->foreign('fmo_personnel_id', 'pm_occ_assignment_personnel_fk')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['preventive_maintenance_occurrence_id', 'fmo_personnel_id'], 'pm_occurrence_assignment_primary');
        });

        Schema::create('preventive_maintenance_executions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('preventive_maintenance_occurrence_id');
            $table->unique('preventive_maintenance_occurrence_id', 'pm_execution_occurrence_unique');
            $table->foreign('preventive_maintenance_occurrence_id', 'pm_execution_occurrence_fk')->references('id')->on('preventive_maintenance_occurrences')->cascadeOnDelete();
            $table->ulid('performed_by_personnel_id');
            $table->foreign('performed_by_personnel_id', 'pm_execution_personnel_fk')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('structured_actions')->nullable();
            $table->string('condition_before', 30)->nullable();
            $table->string('condition_after', 30)->nullable();
            $table->text('findings')->nullable();
            $table->text('action_performed')->nullable();
            $table->text('generated_summary')->nullable();
            $table->text('remarks')->nullable();
            $table->text('materials_used')->nullable();
            $table->text('recommended_corrective_action')->nullable();
            $table->json('component_replacements')->nullable();
            $table->timestamps();
        });

        Schema::create('preventive_maintenance_checklist_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('preventive_maintenance_execution_id');
            $table->foreign('preventive_maintenance_execution_id', 'pm_checklist_execution_fk')->references('id')->on('preventive_maintenance_executions')->cascadeOnDelete();
            $table->string('item_key', 80);
            $table->string('label');
            $table->string('result', 30);
            $table->text('notes')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['preventive_maintenance_execution_id', 'item_key'], 'pm_checklist_execution_item_unique');
        });

        Schema::create('preventive_maintenance_readings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('preventive_maintenance_execution_id');
            $table->foreign('preventive_maintenance_execution_id', 'pm_reading_execution_fk')->references('id')->on('preventive_maintenance_executions')->cascadeOnDelete();
            $table->string('reading_key', 80);
            $table->string('label');
            $table->string('value', 120);
            $table->string('unit', 30)->nullable();
            $table->timestamps();
            $table->unique(['preventive_maintenance_execution_id', 'reading_key'], 'pm_reading_execution_key_unique');
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignUlid('inspectable_asset_id')->nullable()->after('building_location_id')->constrained()->nullOnDelete();
            $table->ulid('preventive_maintenance_occurrence_id')->nullable()->after('inspectable_asset_id');
            $table->foreign('preventive_maintenance_occurrence_id', 'work_orders_pm_occurrence_fk')->references('id')->on('preventive_maintenance_occurrences')->nullOnDelete();
            $table->index(['inspectable_asset_id', 'status'], 'work_orders_asset_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign('work_orders_pm_occurrence_fk');
            $table->dropForeign(['inspectable_asset_id']);
            $table->dropIndex('work_orders_asset_status_index');
            $table->dropColumn(['preventive_maintenance_occurrence_id', 'inspectable_asset_id']);
        });
        Schema::dropIfExists('preventive_maintenance_readings');
        Schema::dropIfExists('preventive_maintenance_checklist_results');
        Schema::dropIfExists('preventive_maintenance_executions');
        Schema::dropIfExists('preventive_maintenance_occurrence_assignments');
        Schema::dropIfExists('preventive_maintenance_occurrences');
        Schema::dropIfExists('preventive_maintenance_plan_assignments');
        Schema::dropIfExists('preventive_maintenance_plans');
        Schema::dropIfExists('preventive_maintenance_templates');
        Schema::table('inspectable_assets', function (Blueprint $table): void {
            $table->dropForeign(['parent_asset_id']);
            $table->dropIndex('assets_parent_lifecycle_index');
            $table->dropColumn(['parent_asset_id', 'asset_class']);
        });
    }
};
