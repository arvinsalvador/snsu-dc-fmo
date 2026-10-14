<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('name')->unique();
            $table->json('checklist');
            $table->json('result_options')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('inspectable_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_code', 40)->unique();
            $table->foreignUlid('asset_type_id')->constrained('asset_types')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('building_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('exact_placement')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('installed_at')->nullable();
            $table->string('inspection_frequency', 20)->default('MONTHLY');
            $table->string('status', 30)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_type_id', 'campus_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspectable_assets');
        Schema::dropIfExists('asset_types');
    }
};
