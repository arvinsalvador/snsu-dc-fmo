<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->string('thumbnail_path')->nullable()->unique()->after('stored_path');
            $table->unsignedInteger('width')->nullable()->after('file_size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedInteger('thumbnail_width')->nullable()->after('height');
            $table->unsignedInteger('thumbnail_height')->nullable()->after('thumbnail_width');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_attachments', function (Blueprint $table): void {
            $table->dropColumn(['thumbnail_path', 'width', 'height', 'thumbnail_width', 'thumbnail_height']);
        });
    }
};
