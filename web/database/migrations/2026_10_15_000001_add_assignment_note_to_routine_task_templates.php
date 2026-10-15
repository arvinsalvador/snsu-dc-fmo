<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->text('assignment_note')->nullable()->after('requires_review');
        });
    }

    public function down(): void
    {
        Schema::table('routine_task_templates', function (Blueprint $table): void {
            $table->dropColumn('assignment_note');
        });
    }
};
