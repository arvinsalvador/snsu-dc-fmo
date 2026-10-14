<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_task_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type', 20)->default('GENERAL')->index();
            $table->string('frequency', 20)->index();
            $table->json('recurrence_config')->nullable();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('building_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('exact_placement')->nullable();
            $table->foreignUlid('asset_type_id')->nullable()->constrained('asset_types')->restrictOnDelete();
            $table->foreignUlid('guided_description_profile_id')->nullable()->constrained('guided_description_profiles')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('due_time')->default('17:00:00');
            $table->boolean('evidence_required')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('routine_task_template_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('routine_task_template_id')->constrained('routine_task_templates', 'id', 'rt_assignment_template_fk')->cascadeOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['routine_task_template_id', 'fmo_personnel_id', 'active_marker'], 'routine_template_active_assignment_unique');
        });

        Schema::create('routine_task_occurrences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('routine_task_template_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 20);
            $table->date('expected_date')->index();
            $table->timestamp('due_at')->index();
            $table->string('status', 30)->default('UPCOMING')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('performed_by_personnel_id')->nullable()->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('referenced_work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignUlid('guided_description_profile_id')->nullable()->constrained('guided_description_profiles')->nullOnDelete();
            $table->json('structured_actions')->nullable();
            $table->string('guided_outcome', 60)->nullable();
            $table->text('generated_summary')->nullable();
            $table->text('additional_remarks')->nullable();
            $table->text('remaining_work')->nullable();
            $table->string('resolution_code', 60)->nullable();
            $table->timestamps();
            $table->unique(['routine_task_template_id', 'period_key'], 'routine_occurrence_period_unique');
        });

        Schema::create('routine_task_occurrence_assignees', function (Blueprint $table): void {
            $table->foreignUlid('routine_task_occurrence_id')->constrained('routine_task_occurrences', 'id', 'rt_occ_assignee_occurrence_fk')->cascadeOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['routine_task_occurrence_id', 'fmo_personnel_id'], 'routine_occurrence_assignee_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_task_occurrence_assignees');
        Schema::dropIfExists('routine_task_occurrences');
        Schema::dropIfExists('routine_task_template_assignments');
        Schema::dropIfExists('routine_task_templates');
    }
};
