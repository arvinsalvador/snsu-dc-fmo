<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', fn (Blueprint $table) => $table->unsignedInteger('execution_cycle')->default(0));
        Schema::table('work_updates', fn (Blueprint $table) => $table->unsignedInteger('execution_cycle')->default(0));
    }

    public function down(): void
    {
        Schema::table('work_updates', fn (Blueprint $table) => $table->dropColumn('execution_cycle'));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('execution_cycle'));
    }
};
