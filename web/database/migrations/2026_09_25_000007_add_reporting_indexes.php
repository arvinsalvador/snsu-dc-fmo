<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'work_orders_status_updated_index');
            $table->index('verified_at');
        });
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->index(['ended_at', 'started_at'], 'work_sessions_active_started_index');
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table): void {
            $table->dropIndex('work_sessions_active_started_index');
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropIndex('work_orders_status_updated_index');
            $table->dropIndex(['verified_at']);
        });
    }
};
