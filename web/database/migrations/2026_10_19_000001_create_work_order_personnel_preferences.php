<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_category_skill', function (Blueprint $table): void {
            $table->foreignId('work_order_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_order_category_id', 'skill_id'], 'work_order_category_skill_unique');
            $table->index('skill_id');
        });

        Schema::create('work_order_personnel_preferences', function (Blueprint $table): void {
            $table->ulid('work_order_id');
            $table->ulid('fmo_personnel_id');
            $table->timestamps();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('fmo_personnel_id')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $table->unique(['work_order_id', 'fmo_personnel_id'], 'work_order_personnel_preference_unique');
            $table->index('work_order_id');
            $table->index('fmo_personnel_id');
        });

        $now = now();
        DB::table('work_orders')
            ->whereNotNull('preferred_fmo_personnel_id')
            ->select(['id', 'preferred_fmo_personnel_id'])
            ->orderBy('id')
            ->chunk(200, function ($orders) use ($now): void {
                DB::table('work_order_personnel_preferences')->insertOrIgnore(
                    $orders->map(fn ($order): array => [
                        'work_order_id' => $order->id,
                        'fmo_personnel_id' => $order->preferred_fmo_personnel_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });

        $mapping = [
            'General Repair / Maintenance' => ['General Maintenance'],
            'Electrical' => ['Electrical'],
            'Plumbing' => ['Plumbing'],
            'Carpentry / Woodworking' => ['Carpentry / Woodworking'],
            'Welding' => ['Welding'],
            'Fabrication / Craft' => ['Fabrication', 'Welding'],
            'Painting' => ['Painting'],
            'Masonry' => ['Masonry'],
            'Air Conditioning' => ['Air Conditioning', 'Electrical'],
            'Furniture / Fixture' => ['Carpentry / Woodworking', 'General Maintenance'],
            'Grounds / Outdoor Maintenance' => ['Grounds Maintenance'],
        ];
        $categoryIds = DB::table('work_order_categories')->whereIn('name', array_keys($mapping))->pluck('id', 'name');
        $skillIds = DB::table('skills')->whereIn('name', collect($mapping)->flatten()->unique())->pluck('id', 'name');
        $rows = collect($mapping)->flatMap(function (array $skillNames, string $categoryName) use ($categoryIds, $skillIds, $now): array {
            if (! isset($categoryIds[$categoryName])) {
                return [];
            }

            return collect($skillNames)->filter(fn (string $name): bool => isset($skillIds[$name]))->map(fn (string $name): array => [
                'work_order_category_id' => $categoryIds[$categoryName],
                'skill_id' => $skillIds[$name],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
        })->all();
        if ($rows !== []) {
            DB::table('work_order_category_skill')->insertOrIgnore($rows);
        }

        Schema::table('work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('preferred_fmo_personnel_id'));
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->ulid('preferred_fmo_personnel_id')->nullable();
            $table->foreign('preferred_fmo_personnel_id')->references('id')->on('fmo_personnel')->restrictOnDelete();
        });

        DB::table('work_order_personnel_preferences')
            ->orderBy('created_at')
            ->get()
            ->groupBy('work_order_id')
            ->each(fn ($preferences, string $workOrderId) => DB::table('work_orders')->where('id', $workOrderId)->update([
                'preferred_fmo_personnel_id' => $preferences->first()->fmo_personnel_id,
            ]));

        Schema::dropIfExists('work_order_personnel_preferences');
        Schema::dropIfExists('work_order_category_skill');
    }
};
