<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_number_sequences');
    }
};
