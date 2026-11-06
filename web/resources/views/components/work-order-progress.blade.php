@props(['order'])
@php
    use App\Enums\WorkOrderStatus as S;
    $labels = ['Submitted', 'Review', 'Assignment', 'Assessment', 'Work', 'Verification', 'Completed'];
    $terminal = in_array($order->status, [S::Disapproved, S::Cancelled], true);
    $current = match ($order->status) {
        S::Submitted => 0,
        S::ForScreening, S::NeedsInformation, S::ForApproval => 1,
        S::Approved => 2,
        S::Assigned, S::ForAssessment, S::AssessmentReview => 3,
        S::ReadyForWork, S::InProgress, S::ForContinuation, S::Paused, S::WaitingForMaterials, S::NeedsInvestigation => 4,
        S::ForVerification => 5,
        S::Completed => 7,
        default => 1,
    };
@endphp
<div class="overflow-x-auto" aria-label="Work Order progress" role="group">
    <ol class="grid min-w-[36rem] grid-cols-7 gap-1">
        @foreach ($labels as $index => $label)
            @php
                $done = ! $terminal && $index < $current;
                $active = ! $terminal && $index === $current;
                $failed = $terminal && $index === $current;
            @endphp
            <li @if ($active) aria-current="step" @endif>
                <div class="flex items-center">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold {{ $done ? 'bg-blue-700 text-white' : ($active ? 'border-2 border-blue-600 bg-white text-blue-700 ring-4 ring-blue-100' : 'bg-slate-100 text-slate-400') }}">
                        @if ($done)<x-icon name="check-circle" size="size-4" />@else{{ $index + 1 }}@endif
                    </span>
                    @unless ($loop->last)<span class="mx-1 h-0.5 grow rounded {{ $done ? 'bg-blue-600' : 'bg-slate-200' }}" aria-hidden="true"></span>@endunless
                </div>
                <p class="mt-1.5 text-xs font-semibold {{ $done || $active ? 'text-slate-900' : 'text-slate-400' }}">{{ $label }}</p>
            </li>
        @endforeach
    </ol>
    @if ($terminal)
        <p class="mt-3 inline-flex items-center gap-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800"><x-icon name="warning" size="size-4" />This Work Order was {{ $order->status === S::Cancelled ? 'cancelled' : 'disapproved' }} and is closed.</p>
    @endif
</div>
