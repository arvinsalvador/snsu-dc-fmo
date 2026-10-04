<?php

namespace App\Providers;

use App\Models\RegistrationReview;
use App\Models\WorkOrderWorkflowEvent;
use App\Observers\RegistrationReviewObserver;
use App\Observers\WorkOrderWorkflowEventObserver;
use App\Services\StoredFileTransaction;
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
        WorkOrderWorkflowEvent::observe(WorkOrderWorkflowEventObserver::class);
        RegistrationReview::observe(RegistrationReviewObserver::class);
    }
}
