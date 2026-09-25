<?php

namespace App\Observers;

use App\Models\RegistrationReview;
use App\Services\WorkflowNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegistrationReviewObserver
{
    public function created(RegistrationReview $review): void
    {
        try {
            app(WorkflowNotificationService::class)->registration($review);
        } catch (Throwable $error) {
            Log::warning('Registration notification failed', ['review_id' => $review->id, 'exception' => $error::class]);
        }
    }
}
