<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('information_context', 20)->nullable();
        });
        Schema::create('work_order_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignUlid('work_order_assignment_id')->constrained('work_order_assignments')->restrictOnDelete();
            $table->foreignUlid('fmo_personnel_id')->constrained('fmo_personnel')->restrictOnDelete();
            $table->string('outcome', 40);
            $table->text('findings');
            $table->text('resource_notes')->nullable();
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['work_order_id', 'assessed_at']);
        });
        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->foreignUlid('work_order_assessment_id')->nullable()->constrained('work_order_assessments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('work_order_assessment_id'));
        Schema::dropIfExists('work_order_assessments');
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('information_context'));
    }
};
