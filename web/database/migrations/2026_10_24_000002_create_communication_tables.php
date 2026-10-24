<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'created_at']);
        });

        Schema::create('work_order_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->timestamps();
            $table->unique(['work_order_id', 'user_id']);
        });

        Schema::create('work_order_internal_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['work_order_id', 'created_at']);
        });

        Schema::create('fmo_contact_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->ulid('related_work_order_id')->nullable();
            $table->foreign('related_work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            $table->string('category', 50)->index();
            $table->string('subject');
            $table->string('status', 20)->default('OPEN')->index();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['requester_user_id', 'updated_at']);
        });

        Schema::create('fmo_contact_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('fmo_contact_thread_id');
            $table->foreign('fmo_contact_thread_id')->references('id')->on('fmo_contact_threads')->restrictOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->timestamps();
            $table->index(['fmo_contact_thread_id', 'created_at']);
        });

        Schema::create('communication_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('attachable_type');
            $table->ulid('attachable_id');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('original_filename');
            $table->string('stored_path')->unique();
            $table->string('thumbnail_path')->nullable()->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('thumbnail_width')->nullable();
            $table->unsignedInteger('thumbnail_height')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_attachments');
        Schema::dropIfExists('fmo_contact_messages');
        Schema::dropIfExists('fmo_contact_threads');
        Schema::dropIfExists('work_order_internal_notes');
        Schema::dropIfExists('work_order_message_reads');
        Schema::dropIfExists('work_order_messages');
    }
};
