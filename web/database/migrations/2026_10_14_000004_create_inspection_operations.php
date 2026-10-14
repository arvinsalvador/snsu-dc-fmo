<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('routine_task_occurrence_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('routine_task_template_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 20);
            $table->string('status', 25)->default('OPEN')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inspection_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspection_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->string('status', 25)->default('NOT_INSPECTED')->index();
            $table->foreignUlid('inspector_personnel_id')->nullable()->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->string('result', 40)->nullable()->index();
            $table->json('checklist_results')->nullable();
            $table->json('structured_actions')->nullable();
            $table->text('generated_summary')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['inspection_run_id', 'inspectable_asset_id']);
        });

        Schema::create('operational_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulidMorphs('attachable');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 30)->default('ROUTINE_EVIDENCE');
            $table->string('evidence_type', 20)->nullable();
            $table->string('original_filename');
            $table->string('stored_path')->unique();
            $table->string('thumbnail_path')->nullable()->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('thumbnail_width')->nullable();
            $table->unsignedInteger('thumbnail_height')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_attachments');
        Schema::dropIfExists('inspection_items');
        Schema::dropIfExists('inspection_runs');
    }
};
