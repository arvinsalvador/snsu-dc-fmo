@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = \Illuminate\Support\Str::of($value)->replace('_', ' ')->title();
    $class = match (true) {
        in_array($value, ['COMPLETED', 'APPROVED', 'ACTIVE', 'READY_FOR_WORK']) => 'status-success',
        in_array($value, ['DISAPPROVED', 'CANCELLED', 'REJECTED', 'INACTIVE']) => 'status-danger',
        in_array($value, ['IN_PROGRESS', 'ASSIGNED', 'FOR_CONTINUATION', 'FOR_VERIFICATION']) => 'status-active',
        in_array($value, ['SUBMITTED', 'NEEDS_INFORMATION']) => 'status-open',
        in_array($value, ['FOR_SCREENING', 'FOR_APPROVAL', 'FOR_ASSESSMENT', 'ASSESSMENT_REVIEW', 'WAITING_FOR_MATERIALS', 'PAUSED', 'PENDING', 'NEEDS_CORRECTION']) => 'status-pending',
        default => 'status-neutral',
    };
@endphp
<span {{ $attributes->merge(['class' => 'status-badge '.$class]) }}><span aria-hidden="true">●</span>{{ $label }}</span>
