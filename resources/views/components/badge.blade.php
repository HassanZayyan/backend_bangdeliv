@props([
    'status',
    'label' => null,
])

@php
    $statusMap = [
        'success'   => 'badge-success',
        'warning'   => 'badge-warning',
        'danger'    => 'badge-danger',
        'info'      => 'badge-info',
    ];
    $class = $statusMap[$status] ?? 'badge-info';
    $text = $label ?? ucfirst($status);
@endphp

<span class="badge {{ $class }}">{{ $text }}</span>
