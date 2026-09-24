<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignUlid('work_order_assignment_id')->constrained('work_order_assignments')->restrictOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_outcome', 40)->nullable();
            $table->text('session_summary')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['fmo_personnel_id', 'active_marker'], 'unique_active_work_session_per_person');
            $table->index(['work_order_id', 'started_at']);
        });

        Schema::create('work_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignUlid('work_session_id')->constrained('work_sessions')->restrictOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 30);
            $table->text('description');
            $table->text('requester_summary')->nullable();
            $table->string('material_description')->nullable();
            $table->text('remaining_work')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['work_order_id', 'recorded_at']);
        });

        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->foreignUlid('work_session_id')->nullable()->constrained('work_sessions')->nullOnDelete();
            $table->foreignUlid('work_update_id')->nullable()->constrained('work_updates')->nullOnDelete();
            $table->string('evidence_type', 20)->nullable();
            $table->boolean('requester_visible')->default(false);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->text('completion_summary')->nullable();
            $table->text('work_performed_summary')->nullable();
            $table->text('non_photo_reason')->nullable();
            $table->foreignId('completion_submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completion_submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completion_submitted_by');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['completion_summary', 'work_performed_summary', 'non_photo_reason', 'completion_submitted_at', 'verified_at', 'verification_note']);
        });
        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_session_id');
            $table->dropConstrainedForeignId('work_update_id');
            $table->dropColumn(['evidence_type', 'requester_visible']);
        });
        Schema::dropIfExists('work_updates');
        Schema::dropIfExists('work_sessions');
    }
};
