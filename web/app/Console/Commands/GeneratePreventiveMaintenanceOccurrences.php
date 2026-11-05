<?php

namespace App\Console\Commands;

use App\Services\PreventiveMaintenanceNoticeService;
use App\Services\PreventiveMaintenanceOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GeneratePreventiveMaintenanceOccurrences extends Command
{
    protected $signature = 'pm:generate {--from=} {--to=}';

    protected $description = 'Generate preventive-maintenance occurrences and send due or overdue notices';

    public function handle(PreventiveMaintenanceOccurrenceService $service, PreventiveMaintenanceNoticeService $notices): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $from = CarbonImmutable::parse($this->option('from') ?: now($timezone)->subDays(7)->toDateString(), $timezone);
        $to = CarbonImmutable::parse($this->option('to') ?: now($timezone)->addDays(45)->toDateString(), $timezone);
        $count = $service->generate($from, $to);
        $notices->send();
        $this->info("Generated {$count} preventive-maintenance occurrence(s).");

        return self::SUCCESS;
    }
}
