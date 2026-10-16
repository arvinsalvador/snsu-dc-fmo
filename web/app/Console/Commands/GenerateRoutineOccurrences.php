<?php

namespace App\Console\Commands;

use App\Services\RoutineNoticeService;
use App\Services\RoutineOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateRoutineOccurrences extends Command
{
    protected $signature = 'routine:generate {--from=} {--to=}';

    protected $description = 'Generate routine task occurrences, backfill missed periods, and send due notices';

    public function handle(RoutineOccurrenceService $service, RoutineNoticeService $notices): int
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $from = CarbonImmutable::parse($this->option('from') ?: now($tz)->subDays(config('routine_operations.generation.backfill_days'))->toDateString(), $tz);
        $to = CarbonImmutable::parse($this->option('to') ?: now($tz)->addDays(config('routine_operations.generation.lookahead_days'))->toDateString(), $tz);
        $count = $service->generate($from, $to);
        $notices->sendDueNotices();
        $this->info("Generated {$count} new routine task occurrence(s).");

        return self::SUCCESS;
    }
}
