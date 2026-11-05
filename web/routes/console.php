<?php

use App\Services\PreventiveMaintenanceNoticeService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('routine:generate')->dailyAt('00:10')->timezone('Asia/Manila')->withoutOverlapping();
Schedule::command('pm:generate')->dailyAt('00:20')->timezone('Asia/Manila')->withoutOverlapping();
Schedule::call(function (): void {
    app(PreventiveMaintenanceOccurrenceService::class)->refreshStatuses();
    app(PreventiveMaintenanceNoticeService::class)->send();
})->hourlyAt(5)->timezone('Asia/Manila')->name('pm:availability-and-notices')->withoutOverlapping();
