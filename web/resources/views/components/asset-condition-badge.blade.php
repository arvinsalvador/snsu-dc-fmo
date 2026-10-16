@props(['condition'])
@php
    $value = $condition instanceof \BackedEnum ? $condition->value : (string) $condition;
    $label = match ($value) {
        'PRISTINE' => 'Pristine',
        'GOOD' => 'Good',
        'FAIR' => 'Fair',
        'NEEDS_REPAIR' => 'Needs Repair',
        'DAMAGED' => 'Damaged',
        'BROKEN' => 'Broken',
        'UNUSABLE' => 'Unusable',
        default => str($value)->replace('_', ' ')->title(),
    };
    $style = match ($value) {
        'PRISTINE', 'GOOD' => 'status-success',
        'FAIR' => 'status-warning',
        default => 'status-danger',
    };
@endphp
<span {{ $attributes->class(['status-badge', $style]) }}>{{ $label }}</span>
