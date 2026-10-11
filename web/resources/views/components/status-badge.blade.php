@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = \Illuminate\Support\Str::of($value)->replace('_', ' ')->title();
    $class = match (true) {
        in_array($value, ['COMPLETED', 'APPROVED', 'ACTIVE', 'READY_FOR_WORK', 'AVAILABLE']) => 'status-success',
        in_array($value, ['DISAPPROVED', 'CANCELLED', 'REJECTED', 'INACTIVE', 'SUSPENDED', 'DEACTIVATED', 'ARCHIVED']) => 'status-danger',
        in_array($value, ['IN_PROGRESS', 'ASSIGNED', 'FOR_CONTINUATION', 'FOR_VERIFICATION', 'ASSESSING']) => 'status-active',
        in_array($value, ['SUBMITTED', 'NEEDS_INFORMATION', 'NEED_INFORMATION']) => 'status-open',
        in_array($value, ['FOR_SCREENING', 'FOR_APPROVAL', 'FOR_ASSESSMENT', 'ASSESSMENT_REVIEW', 'WAITING_FOR_MATERIALS', 'PAUSED', 'PENDING', 'NEEDS_CORRECTION', 'ON_LEAVE', 'UNAVAILABLE', 'NEEDS_INVESTIGATION']) => 'status-pending',
        default => 'status-neutral',
    };
@endphp
<span {{ $attributes->merge(['class' => 'status-badge '.$class]) }}><span aria-hidden="true">●</span>{{ $label }}</span>
