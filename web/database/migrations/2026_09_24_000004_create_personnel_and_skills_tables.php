<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fmo_personnel', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('personnel_identifier')->nullable()->unique();
            $table->string('designation')->nullable()->index();
            $table->string('employment_type', 25)->nullable()->index();
            $table->date('start_date')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('personnel_status', 25)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('fmo_personnel_skill', function (Blueprint $table): void {
            $table->ulid('fmo_personnel_id');
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->primary(['fmo_personnel_id', 'skill_id']);
            $table->foreign('fmo_personnel_id')->references('id')->on('fmo_personnel')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fmo_personnel_skill');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('fmo_personnel');
    }
};
