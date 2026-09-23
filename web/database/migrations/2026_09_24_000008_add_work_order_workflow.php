<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('recommendation', 15)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
        });
        Schema::create('work_order_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25);
            $table->text('internal_note')->nullable();
            $table->text('requester_message')->nullable();
            $table->timestamp('created_at');
            $table->index(['work_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_workflow_events');
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['recommendation', 'decided_at']);
        });
    }
};
