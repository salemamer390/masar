@props(['value', 'label' => null])
@php
    $tone = match((string) $value) {
        'Approved', 'Active', 'Present', 'Paid', 'Completed' => 'success',
        'Pending', 'Manager Approved', 'Draft', 'Late', 'Leave', 'On Leave' => 'warning',
        'Rejected', 'Inactive', 'Absent', 'Locked', 'Terminated', 'Suspended' => 'danger',
        default => 'muted',
    };
@endphp
<span {{ $attributes->merge(['class' => 'ui-status-badge '.$tone]) }}>{{ $label ?? arabic_label($value) }}</span>
