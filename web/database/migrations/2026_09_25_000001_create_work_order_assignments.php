<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->text('assignment_note')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamp('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('unassignment_reason')->nullable();
            $table->timestamps();
            $table->unique(['work_order_id', 'fmo_personnel_id', 'active_marker'], 'unique_active_work_order_personnel');
            $table->index(['fmo_personnel_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_assignments');
    }
};
