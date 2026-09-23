<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->string('name')->unique();
            $t->string('short_name')->nullable();
            $t->text('description')->nullable();
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });
        Schema::create('buildings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('campus_id')->constrained()->restrictOnDelete();
            $t->string('code')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
            $t->unique(['campus_id', 'name']);
            $t->unique(['campus_id', 'code']);
        });
        Schema::create('floors', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('building_id')->constrained()->restrictOnDelete();
            $t->string('code')->nullable();
            $t->string('name');
            $t->unsignedInteger('display_order')->default(0);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
            $t->unique(['building_id', 'name']);
        });
        Schema::create('building_locations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('building_id')->constrained()->restrictOnDelete();
            $t->foreignId('floor_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 30)->index();
            $t->string('code')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
            $t->unique(['building_id', 'floor_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_locations');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('campuses');
    }
};
