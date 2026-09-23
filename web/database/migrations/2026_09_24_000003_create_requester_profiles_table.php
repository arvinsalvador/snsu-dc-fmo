<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requester_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('institutional_id')->nullable()->index();
            $table->string('department')->nullable();
            $table->string('college')->nullable();
            $table->string('program')->nullable();
            $table->string('year_level', 30)->nullable();
            $table->string('organizational_office')->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requester_profiles');
    }
};
