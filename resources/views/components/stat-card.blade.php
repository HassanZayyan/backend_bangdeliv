@props([
    'title',
    'value',
    'icon' => 'bx-bar-chart',
    'color' => 'primary',   // primary | info | success | warning | danger
    'change' => null,
    'changeType' => 'positive', // positive | negative | neutral
])

@php
    $colorMap = [
        'primary' => ['bg' => 'rgba(255,119,0,0.1)',  'color' => 'var(--color-primary)'],
        'info'    => ['bg' => 'rgba(59,130,246,0.1)', 'color' => 'var(--color-info)'],
        'success' => ['bg' => 'rgba(16,185,129,0.1)', 'color' => 'var(--color-success)'],
        'warning' => ['bg' => 'rgba(245,158,11,0.1)', 'color' => 'var(--color-warning)'],
        'danger'  => ['bg' => 'rgba(239,68,68,0.1)',  'color' => 'var(--color-danger)'],
    ];
    $c = $colorMap[$color] ?? $colorMap['primary'];
    $valueClass = match($color) {
        'success' => 'text-success',
        'primary' => 'text-primary',
        default   => '',
    };
@endphp

<div class="stat-card">
    <div class="stat-card-header">
        <span class="stat-title">{{ $title }}</span>
        <div class="stat-icon" style="background: {{ $c['bg'] }}; color: {{ $c['color'] }};">
            <i class='bx {{ $icon }}'></i>
        </div>
    </div>
    <div class="stat-value {{ $valueClass }}">{{ $value }}</div>
    @if($change)
        <div class="stat-change {{ $changeType }}">
            @if($changeType === 'positive') <i class='bx bx-up-arrow-alt'></i>
            @elseif($changeType === 'negative') <i class='bx bx-down-arrow-alt'></i>
            @endif
            {{ $change }}
        </div>
    @endif
</div>
