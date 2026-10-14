<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guided_description_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('context', 30)->default('GENERAL');
            $table->json('actions');
            $table->json('outcomes');
            $table->json('sentence_templates')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('work_order_categories', function (Blueprint $table): void {
            $table->foreignUlid('guided_description_profile_id')->nullable()->after('description')
                ->constrained('guided_description_profiles')->nullOnDelete();
        });

        Schema::table('work_updates', function (Blueprint $table): void {
            $table->foreignUlid('guided_description_profile_id')->nullable()->after('type')
                ->constrained('guided_description_profiles')->nullOnDelete();
            $table->json('structured_actions')->nullable()->after('guided_description_profile_id');
            $table->string('guided_outcome', 60)->nullable()->after('structured_actions');
            $table->text('generated_summary')->nullable()->after('guided_outcome');
            $table->text('additional_remarks')->nullable()->after('generated_summary');
        });
    }

    public function down(): void
    {
        Schema::table('work_updates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('guided_description_profile_id');
            $table->dropColumn(['structured_actions', 'guided_outcome', 'generated_summary', 'additional_remarks']);
        });
        Schema::table('work_order_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('guided_description_profile_id'));
        Schema::dropIfExists('guided_description_profiles');
    }
};
