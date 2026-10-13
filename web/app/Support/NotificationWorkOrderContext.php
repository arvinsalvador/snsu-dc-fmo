<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationWorkOrderContext
{
    /**
     * Resolve every Work Order represented on the current notification page in one query.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<string, array<string, mixed>>
     */
    public function load(Collection $notifications): Collection
    {
        $ids = $notifications->pluck('data')
            ->map(fn (array $data): ?string => $data['work_order_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->baseQuery()
            ->whereIn('work_orders.id', $ids)
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->id => [
                'id' => (string) $row->id,
                'number' => $row->work_order_number,
                'subject' => $row->subject,
                'requester_name' => $row->requester_name,
                'requester_type' => $row->requester_type ? Str::headline($row->requester_type) : null,
                'requester_department' => $row->department ?: ($row->college ?: $row->organizational_office),
                'building' => $row->building_name,
                'location' => $row->location_name,
                'category' => $row->category_name,
            ]]);
    }

    /**
     * Build a bound, server-side subquery for Work Orders matching every search token.
     */
    public function matchingIds(string $search): Builder
    {
        $tokens = collect(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY))
            ->take(8);

        $query = DB::table('work_orders')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'work_orders.requester_id')
            ->select('work_orders.id');

        foreach ($tokens as $token) {
            $term = '%'.addcslashes($token, '%_\\').'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('work_orders.work_order_number', 'like', $term)
                    ->orWhere('work_orders.subject', 'like', $term)
                    ->orWhere('requesters.name', 'like', $term);
            });
        }

        return $query;
    }

    private function baseQuery(): Builder
    {
        return DB::table('work_orders')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'work_orders.requester_id')
            ->leftJoin('requester_profiles', 'requester_profiles.user_id', '=', 'requesters.id')
            ->leftJoin('buildings', 'buildings.id', '=', 'work_orders.building_id')
            ->leftJoin('building_locations', 'building_locations.id', '=', 'work_orders.building_location_id')
            ->leftJoin('work_order_categories', 'work_order_categories.id', '=', 'work_orders.work_order_category_id')
            ->select([
                'work_orders.id',
                'work_orders.work_order_number',
                'work_orders.subject',
                'requesters.name as requester_name',
                'requesters.user_type as requester_type',
                'requester_profiles.department',
                'requester_profiles.college',
                'requester_profiles.organizational_office',
                'buildings.name as building_name',
                'building_locations.name as location_name',
                'work_order_categories.name as category_name',
            ]);
    }
}
