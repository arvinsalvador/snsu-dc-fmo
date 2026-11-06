<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspectable_assets', function (Blueprint $table): void {
            $table->json('specifications')->nullable()->after('notes');
            $table->unsignedBigInteger('current_odometer_km')->nullable()->after('specifications');
            $table->timestamp('odometer_updated_at')->nullable()->after('current_odometer_km');
        });

        Schema::create('transport_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });

        Schema::create('transport_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('request_number', 30)->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 500);
            $table->string('origin');
            $table->string('destination');
            $table->date('travel_date')->index();
            $table->dateTime('expected_departure_at')->nullable();
            $table->dateTime('expected_return_at')->nullable();
            $table->unsignedSmallInteger('passenger_count')->default(1);
            $table->text('passenger_details')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 25)->default('PENDING_APPROVAL')->index();

            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('approval_authority', 40)->nullable();
            $table->string('approval_instructed_by')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();

            $table->ulid('driver_personnel_id')->nullable();
            $table->foreign('driver_personnel_id')->references('id')->on('fmo_personnel')->restrictOnDelete();
            $table->foreignUlid('inspectable_asset_id')->nullable()->constrained('inspectable_assets')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at')->nullable();

            $table->dateTime('actual_departed_at')->nullable();
            $table->dateTime('actual_arrived_at')->nullable();
            $table->unsignedBigInteger('odometer_before')->nullable();
            $table->unsignedBigInteger('odometer_after')->nullable();
            $table->unsignedBigInteger('distance_km')->nullable();
            $table->string('fuel_before', 20)->nullable();
            $table->string('fuel_after', 20)->nullable();
            $table->text('trip_remarks')->nullable();
            $table->boolean('vehicle_issue_observed')->default(false);
            $table->text('vehicle_issue_remarks')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index(['driver_personnel_id', 'status']);
            $table->index(['inspectable_asset_id', 'status', 'completed_at'], 'transport_vehicle_history_index');
        });

        Schema::create('transport_request_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transport_request_id')->constrained('transport_requests')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['transport_request_id', 'created_at']);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignUlid('transport_request_id')->nullable()->after('preventive_maintenance_occurrence_id')->constrained('transport_requests')->nullOnDelete();
        });

        Schema::table('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->string('trigger_type', 20)->default('CALENDAR')->after('maintenance_type')->index();
            $table->unsignedInteger('odometer_interval_km')->nullable()->after('interval_value');
            $table->unsignedBigInteger('last_service_odometer_km')->nullable()->after('odometer_interval_km');
            $table->unsignedBigInteger('next_due_odometer_km')->nullable()->after('last_service_odometer_km');
            $table->unsignedInteger('due_soon_km')->nullable()->after('next_due_odometer_km');
        });

        Schema::table('preventive_maintenance_occurrences', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->change();
            $table->unsignedBigInteger('due_odometer_km')->nullable()->after('due_at');
            $table->unsignedBigInteger('service_odometer_km')->nullable()->after('due_odometer_km');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_maintenance_occurrences', function (Blueprint $table): void {
            $table->dropColumn(['due_odometer_km', 'service_odometer_km']);
        });
        Schema::table('preventive_maintenance_plans', function (Blueprint $table): void {
            $table->dropIndex(['trigger_type']);
            $table->dropColumn(['trigger_type', 'odometer_interval_km', 'last_service_odometer_km', 'next_due_odometer_km', 'due_soon_km']);
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transport_request_id');
        });
        Schema::dropIfExists('transport_request_events');
        Schema::dropIfExists('transport_requests');
        Schema::dropIfExists('transport_number_sequences');
        Schema::table('inspectable_assets', function (Blueprint $table): void {
            $table->dropColumn(['specifications', 'current_odometer_km', 'odometer_updated_at']);
        });
    }
};
