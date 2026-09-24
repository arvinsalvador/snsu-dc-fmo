<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_client_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_operation_id');
            $table->uuid('installation_id')->nullable();
            $table->string('type', 40);
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamp('client_created_at')->nullable();
            $table->timestamp('processed_at');
            $table->unique(['user_id', 'client_operation_id']);
            $table->index(['user_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_client_operations');
    }
};
