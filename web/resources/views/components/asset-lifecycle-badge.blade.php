@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = str($value)->replace('_', ' ')->title();
    $style = match ($value) {
        'ACTIVE' => 'status-active',
        'UNDER_REPAIR', 'FOR_DISPOSAL_REVIEW' => 'status-warning',
        'APPROVED_FOR_DISPOSAL', 'DISPOSED' => 'status-danger',
        default => 'status-neutral',
    };
@endphp
<span {{ $attributes->class(['status-badge', $style]) }}>{{ $label }}</span>
