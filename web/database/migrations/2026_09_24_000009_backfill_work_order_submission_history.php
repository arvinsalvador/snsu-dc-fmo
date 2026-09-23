<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('work_orders')->orderBy('id')->chunk(100, function ($orders): void {
            foreach ($orders as $order) {
                if (DB::table('work_order_workflow_events')->where('work_order_id', $order->id)->exists()) {
                    continue;
                }
                DB::table('work_order_workflow_events')->insert([
                    'work_order_id' => $order->id,
                    'actor_id' => $order->requester_id,
                    'action' => 'SUBMITTED',
                    'from_status' => null,
                    'to_status' => $order->status,
                    'created_at' => $order->submitted_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Historical events are intentionally retained.
    }
};
