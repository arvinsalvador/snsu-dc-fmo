<?php

namespace App\Providers;

use App\Models\RegistrationReview;
use App\Models\WorkOrderWorkflowEvent;
use App\Observers\RegistrationReviewObserver;
use App\Observers\WorkOrderWorkflowEventObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
