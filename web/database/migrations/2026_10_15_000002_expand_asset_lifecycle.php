<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asset_types', 'description')) {
            Schema::table('asset_types', function (Blueprint $table): void {
                $table->text('description')->nullable()->after('name');
                $table->string('default_inspection_frequency', 20)->default('MONTHLY')->after('description');
            });
        }
        if (! Schema::hasColumn('inspectable_assets', 'condition')) {
            Schema::table('inspectable_assets', function (Blueprint $table): void {
                $table->string('condition', 30)->default('GOOD')->after('status')->index();
                $table->string('lifecycle_status', 40)->default('ACTIVE')->after('condition')->index();
                $table->string('qr_token', 64)->nullable()->after('lifecycle_status')->unique();
                $table->timestamp('qr_regenerated_at')->nullable()->after('qr_token');
                $table->foreignId('qr_regenerated_by')->nullable()->after('qr_regenerated_at')->constrained('users')->nullOnDelete();
                $table->timestamp('last_inspected_at')->nullable()->after('installed_at')->index();
            });
            DB::table('inspectable_assets')->orderBy('id')->each(function (object $asset): void {
                DB::table('inspectable_assets')->where('id', $asset->id)->update([
                    'qr_token' => Str::random(48),
                    'lifecycle_status' => in_array($asset->status, ['RETIRED'], true) ? 'RETIRED' : 'ACTIVE',
                    'condition' => match ($asset->status) {
                        'OUT_OF_SERVICE' => 'BROKEN',
                        'MISSING' => 'UNUSABLE',
                        default => 'GOOD',
                    },
                ]);
            });
        }

        Schema::table('inspection_items', function (Blueprint $table): void {
            $table->dropForeign(['inspection_run_id']);
            $table->dropUnique(['inspection_run_id', 'inspectable_asset_id']);
        });
        Schema::table('inspection_items', function (Blueprint $table): void {
            $table->ulid('inspection_run_id')->nullable()->change();
            $table->foreign('inspection_run_id')->references('id')->on('inspection_runs')->cascadeOnDelete();
            $table->string('condition', 30)->nullable()->after('result')->index();
            $table->string('recommended_action', 40)->nullable()->after('condition');
            $table->unique(['inspection_run_id', 'inspectable_asset_id']);
        });

        Schema::create('asset_action_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('inspection_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->index();
            $table->string('status', 30)->default('PENDING')->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->text('problem_description');
            $table->text('recommended_action')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('decision', 30)->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->index(['inspectable_asset_id', 'status']);
        });

        Schema::create('asset_maintenance_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('asset_action_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->text('work_performed');
            $table->text('parts_materials')->nullable();
            $table->string('result', 30)->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inspectable_asset_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('asset_action_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 50);
            $table->string('from_lifecycle', 40)->nullable();
            $table->string('to_lifecycle', 40)->nullable();
            $table->string('from_condition', 30)->nullable();
            $table->string('to_condition', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_lifecycle_events');
        Schema::dropIfExists('asset_maintenance_records');
        Schema::dropIfExists('asset_action_requests');
        Schema::table('inspection_items', function (Blueprint $table): void {
            $table->dropColumn(['condition', 'recommended_action']);
        });
        Schema::table('inspectable_assets', function (Blueprint $table): void {
            $table->dropForeign(['qr_regenerated_by']);
            $table->dropColumn(['condition', 'lifecycle_status', 'qr_token', 'qr_regenerated_at', 'qr_regenerated_by', 'last_inspected_at']);
        });
        Schema::table('asset_types', function (Blueprint $table): void {
            $table->dropColumn(['description', 'default_inspection_frequency']);
        });
    }
};
