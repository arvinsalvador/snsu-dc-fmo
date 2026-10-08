<?php

namespace App\Console\Commands;

use App\Models\FmoPersonnel;
use App\Services\PersonnelAccessService;
use Illuminate\Console\Command;

class SyncPersonnelAccess extends Command
{
    protected $signature = 'personnel:sync-staff-access {--dry-run : Show the number of eligible accounts without changing roles}';

    protected $description = 'Add the FMO Staff role to approved active personnel, preserving all existing roles and direct permissions';

    public function handle(PersonnelAccessService $access): int
    {
        $people = FmoPersonnel::with('user.roles')->where('personnel_status', 'ACTIVE')->whereNull('archived_at')
            ->whereHas('user', fn ($query) => $query->where('status', 'APPROVED')->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'FMO Staff')))->get();

        if ($this->option('dry-run')) {
            $this->info("{$people->count()} approved active personnel account(s) need the FMO Staff role.");

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($people as $personnel) {
            $count += (int) $access->provision($personnel);
        }
        $this->info("Provisioned FMO Staff access for {$count} account(s). Existing roles and direct permissions were preserved.");

        return self::SUCCESS;
    }
}
