<?php

namespace App\Providers;

use App\Models\RegistrationReview;
use App\Models\User;
use App\Models\WorkOrderWorkflowEvent;
use App\Observers\RegistrationReviewObserver;
use App\Observers\WorkOrderWorkflowEventObserver;
use App\Services\StoredFileTransaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(StoredFileTransaction::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('work-orders.create-request', static fn (User $user): bool =>
            $user->can('work_orders.create')
            && ! $user->personnelProfile()->exists()
            && ! $user->hasAnyRole(['FMO Head', 'FMO Dispatcher', 'FMO Staff'])
        );

        WorkOrderWorkflowEvent::observe(WorkOrderWorkflowEventObserver::class);
        RegistrationReview::observe(RegistrationReviewObserver::class);
    }
}
