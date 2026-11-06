@props(['transport'])
@php
    use App\Enums\TransportStatus;
    $status = $transport->status;
    $fmt = fn ($value) => $value?->timezone(config('app.timezone'))->format('M j, g:i A');
    $steps = [
        ['label' => 'Submitted', 'at' => $transport->created_at, 'done' => true],
        ['label' => 'Approved', 'at' => $transport->approved_at, 'done' => (bool) $transport->approved_at],
        ['label' => 'Assigned', 'at' => $transport->assigned_at, 'done' => (bool) $transport->assigned_at],
        ['label' => 'Completed', 'at' => $transport->completed_at, 'done' => $status === TransportStatus::Completed],
    ];
    $terminal = match ($status) {
        TransportStatus::Rejected => ['Rejected', $transport->rejected_at],
        TransportStatus::Cancelled => ['Cancelled', $transport->cancelled_at],
        default => null,
    };
    if ($terminal) {
        $steps = array_slice($steps, 0, $transport->approved_at ? 2 : 1);
        $steps[] = ['label' => $terminal[0], 'at' => $terminal[1], 'done' => true, 'danger' => true];
    }
    $currentIndex = collect($steps)->search(fn ($step) => ! $step['done']);
@endphp
<ol class="grid gap-3 sm:grid-cols-{{ count($steps) }}" aria-label="Request progress">
    @foreach ($steps as $index => $step)
        @php
            $danger = $step['danger'] ?? false;
        @endphp
        <li class="flex items-start gap-3 sm:block" @if ($currentIndex === $index) aria-current="step" @endif>
            <div class="flex items-center sm:mb-2">
                <span class="grid size-8 shrink-0 place-items-center rounded-full text-xs font-bold ring-4 {{ $danger ? 'bg-rose-600 text-white ring-rose-100' : ($step['done'] ? 'bg-blue-700 text-white ring-blue-100' : ($currentIndex === $index ? 'border-2 border-blue-600 bg-white text-blue-700 ring-blue-50' : 'bg-slate-100 text-slate-400 ring-white')) }}">
                    @if ($danger)<x-icon name="close" size="size-4" />@elseif ($step['done'])<x-icon name="check-circle" size="size-4" />@else{{ $index + 1 }}@endif
                </span>
                @unless ($loop->last)<span class="ml-2 hidden h-0.5 grow rounded sm:block {{ $step['done'] && ($steps[$index + 1]['done'] ?? false) ? 'bg-blue-600' : 'bg-slate-200' }}" aria-hidden="true"></span>@endunless
            </div>
            <div>
                <p class="text-sm font-semibold {{ $danger ? 'text-rose-800' : ($step['done'] || $currentIndex === $index ? 'text-slate-900' : 'text-slate-400') }}">{{ $step['label'] }}</p>
                <p class="text-xs text-slate-500">{{ $step['at'] ? $fmt($step['at']) : ($currentIndex === $index ? 'Up next' : 'Pending') }}</p>
            </div>
        </li>
    @endforeach
</ol>
