<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->unsignedInteger('display_order')->default(0);
            $t->timestamps();
        });
        Schema::create('work_orders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('work_order_number')->unique();
            $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('work_order_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('campus_id')->constrained()->restrictOnDelete();
            $t->foreignId('building_id')->constrained()->restrictOnDelete();
            $t->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('building_location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('subject');
            $t->text('description');
            $t->string('urgency', 15)->default('NORMAL');
            $t->ulid('preferred_fmo_personnel_id')->nullable();
            $t->foreign('preferred_fmo_personnel_id')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $t->string('status', 25)->default('SUBMITTED')->index();
            $t->timestamp('submitted_at')->index();
            $t->timestamps();
            $t->index(['requester_id', 'submitted_at']);
        });
        Schema::create('work_order_attachments', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('work_order_id');
            $t->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 30)->default('REQUEST_INITIAL');
            $t->string('original_filename');
            $t->string('stored_path')->unique();
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('file_size');
            $t->string('caption')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_attachments');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('work_order_categories');
    }
};
