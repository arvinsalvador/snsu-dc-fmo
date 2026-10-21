<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_order_category_skill')) {
            return;
        }

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

        $categoryIds = DB::table('work_order_categories')
            ->whereIn('name', array_keys($mapping))
            ->pluck('id', 'name');
        $skillIds = DB::table('skills')
            ->whereIn('name', collect($mapping)->flatten()->unique())
            ->pluck('id', 'name');
        $now = now();
        $rows = collect($mapping)->flatMap(function (array $skillNames, string $categoryName) use ($categoryIds, $skillIds, $now): array {
            if (! isset($categoryIds[$categoryName])) {
                return [];
            }

            return collect($skillNames)
                ->filter(fn (string $skillName): bool => isset($skillIds[$skillName]))
                ->map(fn (string $skillName): array => [
                    'work_order_category_id' => $categoryIds[$categoryName],
                    'skill_id' => $skillIds[$skillName],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
        })->all();

        if ($rows !== []) {
            DB::table('work_order_category_skill')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Deliberately preserve mappings because they may have existed before this repair.
    }
};
