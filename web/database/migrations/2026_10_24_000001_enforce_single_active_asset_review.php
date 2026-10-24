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
        Schema::table('asset_action_requests', function (Blueprint $table): void {
            $table->unsignedTinyInteger('active_marker')->nullable()->after('status');
        });

        DB::transaction(function (): void {
            $assets = DB::table('inspectable_assets')->select(['id', 'lifecycle_status', 'condition'])->get();

            foreach ($assets as $asset) {
                $pending = DB::table('asset_action_requests')
                    ->where('inspectable_asset_id', $asset->id)
                    ->where('status', 'PENDING')
                    ->orderByDesc('requested_at')
                    ->get();

                if ($asset->lifecycle_status === 'FOR_DISPOSAL_REVIEW') {
                    $keep = $pending->firstWhere('type', 'DISPOSAL');
                    $source = DB::table('asset_action_requests')
                        ->where('inspectable_asset_id', $asset->id)
                        ->where('decision', 'NOT_REPAIRABLE')
                        ->orderByDesc('reviewed_at')
                        ->first() ?? $pending->first();

                    DB::table('asset_action_requests')
                        ->where('inspectable_asset_id', $asset->id)
                        ->where('status', 'PENDING')
                        ->when($keep, fn ($query) => $query->where('id', '!=', $keep->id))
                        ->update([
                            'status' => 'SUPERSEDED',
                            'active_marker' => null,
                            'reviewed_by' => $source?->reviewed_by ?? $source?->requested_by,
                            'reviewed_at' => now(),
                            'decision' => 'SUPERSEDED',
                            'decision_reason' => 'Replaced by the authoritative disposal review during lifecycle synchronization.',
                            'updated_at' => now(),
                        ]);

                    if (! $keep && $source) {
                        $requestId = (string) Str::ulid();
                        $actorId = $source->reviewed_by ?? $source->requested_by;
                        DB::table('asset_action_requests')->insert([
                            'id' => $requestId,
                            'inspectable_asset_id' => $asset->id,
                            'inspection_item_id' => $source->inspection_item_id,
                            'type' => 'DISPOSAL',
                            'status' => 'PENDING',
                            'active_marker' => 1,
                            'requested_by' => $actorId,
                            'requested_at' => $source->reviewed_at ?? $source->requested_at ?? now(),
                            'problem_description' => $source->decision_reason ?: $source->problem_description,
                            'recommended_action' => 'Review the asset for disposal.',
                            'reviewed_by' => null,
                            'reviewed_at' => null,
                            'decision' => null,
                            'decision_reason' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('asset_lifecycle_events')->insert([
                            'id' => (string) Str::ulid(),
                            'inspectable_asset_id' => $asset->id,
                            'asset_action_request_id' => $requestId,
                            'actor_id' => $actorId,
                            'event' => 'DISPOSAL_REVIEW_BACKFILLED',
                            'from_lifecycle' => 'FOR_DISPOSAL_REVIEW',
                            'to_lifecycle' => 'FOR_DISPOSAL_REVIEW',
                            'from_condition' => $asset->condition,
                            'to_condition' => $asset->condition,
                            'remarks' => 'Created the missing disposal review and superseded the conflicting repair review.',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } elseif ($keep) {
                        DB::table('asset_action_requests')->where('id', $keep->id)->update(['active_marker' => 1]);
                    }

                    continue;
                }

                $keep = $pending->first();
                if ($keep) {
                    DB::table('asset_action_requests')
                        ->where('inspectable_asset_id', $asset->id)
                        ->where('status', 'PENDING')
                        ->where('id', '!=', $keep->id)
                        ->update([
                            'status' => 'SUPERSEDED',
                            'active_marker' => null,
                            'reviewed_by' => $keep->requested_by,
                            'reviewed_at' => now(),
                            'decision' => 'SUPERSEDED',
                            'decision_reason' => 'Superseded while enforcing a single active review per asset.',
                            'updated_at' => now(),
                        ]);
                    DB::table('asset_action_requests')->where('id', $keep->id)->update(['active_marker' => 1]);
                }
            }
        });

        Schema::table('asset_action_requests', function (Blueprint $table): void {
            $table->unique(['inspectable_asset_id', 'active_marker'], 'asset_action_one_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asset_action_requests', function (Blueprint $table): void {
            $table->dropUnique('asset_action_one_active_unique');
            $table->dropColumn('active_marker');
        });
    }
};
