<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Dashboard - BangDeliv')</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <link rel="shortcut icon" type="image/jpeg" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <link rel="apple-touch-icon" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    @vite('resources/js/app.js')
    <script>
        (function() {
            var saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>
<body>
@php
    $adminNotificationSummary = $adminNotificationSummary ?? [
        'total_pending' => 0,
        'pending_drivers' => 0,
        'pending_payment_proofs' => 0,
        'items' => [],
    ];
    $adminNavigationCounts = $adminNavigationCounts ?? [
        'orders' => 0,
        'drivers' => 0,
        'verification' => 0,
        'shopping' => 0,
        'courier' => 0,
        'ride' => 0,
    ];
    $adminOrderServiceFilters = $adminOrderServiceFilters ?? [
        'all' => ['nav_label' => 'Semua', 'code' => null],
        'shopping' => ['nav_label' => 'Titip Belanja', 'code' => 'SHOPPING'],
        'courier' => ['nav_label' => 'Kurir', 'code' => 'COURIER'],
        'ride' => ['nav_label' => 'Antar Jemput', 'code' => 'RIDE'],
    ];
    $isOrdersRoute = Request::routeIs('admin.orders.*');
    $orderServiceFilter = request()->query('service', 'all');
    if (! array_key_exists($orderServiceFilter, $adminOrderServiceFilters)) {
        $orderServiceFilter = 'all';
    }
    $adminUser = Auth::user();
    $adminAvatarUrl = app(\App\Services\Admin\AdminMediaUrlResolver::class)->publicUrl($adminUser?->avatar);
@endphp
<div class="admin-layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="{{ asset('images/logo.jpg') }}" alt="BangDeliv">
            </div>
            <div class="sidebar-brand">
                <span>BangDeliv</span>
                <small>Admin Panel</small>
            </div>
        </div>

        <nav class="sidebar-menu" aria-label="Navigasi admin">
            <div class="menu-category">Utama</div>
            <a href="{{ route('admin.dashboard') }}" class="menu-item {{ Request::routeIs('admin.dashboard') ? 'active' : '' }}">
                <i class="bx bxs-dashboard" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>

            <div class="menu-category">Operasional</div>
            <div class="menu-group">
                <a href="{{ route('admin.orders.index', ['service' => 'all']) }}" class="menu-item {{ $isOrdersRoute ? 'active' : '' }}">
                    <i class="bx bx-receipt" aria-hidden="true"></i>
                    <span>Pesanan</span>
                    @if(($adminNavigationCounts['orders'] ?? 0) > 0)
                        <span class="menu-badge">{{ $adminNavigationCounts['orders'] }}</span>
                    @endif
                </a>
                <div class="menu-submenu">
                    @foreach($adminOrderServiceFilters as $filterKey => $filter)
                        <a href="{{ route('admin.orders.index', ['service' => $filterKey]) }}" class="menu-subitem {{ $isOrdersRoute && $orderServiceFilter === $filterKey ? 'active' : '' }}">
                            {{ $filter['nav_label'] ?? $filter['label'] ?? ucfirst($filterKey) }}
                            @if(($filter['code'] ?? null) !== null)
                                <span>{{ $adminNavigationCounts[$filterKey] ?? 0 }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>

            <a href="{{ route('admin.drivers.index') }}" class="menu-item {{ Request::routeIs('admin.drivers.index', 'admin.drivers.show') ? 'active' : '' }}">
                <i class="bx bx-cycling" aria-hidden="true"></i>
                <span>Driver</span>
                @if(($adminNavigationCounts['drivers'] ?? 0) > 0)
                    <span class="menu-badge is-muted">{{ $adminNavigationCounts['drivers'] }}</span>
                @endif
            </a>
            <a href="{{ route('admin.customers.index') }}" class="menu-item {{ Request::routeIs('admin.customers.*') ? 'active' : '' }}">
                <i class="bx bxs-group" aria-hidden="true"></i>
                <span>Pelanggan</span>
            </a>
            <a href="{{ route('admin.restaurants.index') }}" class="menu-item {{ Request::routeIs('admin.restaurants.*') ? 'active' : '' }}">
                <i class="bx bx-store" aria-hidden="true"></i>
                <span>Restoran / Warung</span>
            </a>

            <div class="menu-category">Sistem</div>
            <a href="{{ route('admin.verification') }}" class="menu-item {{ Request::routeIs('admin.verification*') ? 'active' : '' }}">
                <i class="bx bx-check-shield" aria-hidden="true"></i>
                <span>Verifikasi Driver</span>
                @if(($adminNavigationCounts['verification'] ?? 0) > 0)
                    <span class="menu-badge is-warning">{{ $adminNavigationCounts['verification'] }}</span>
                @endif
            </a>
            <a href="{{ route('admin.settings') }}" class="menu-item {{ Request::routeIs('admin.settings') ? 'active' : '' }}">
                <i class="bx bx-cog" aria-hidden="true"></i>
                <span>Pengaturan</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-avatar {{ $adminAvatarUrl ? 'has-image' : '' }}">
                @if($adminAvatarUrl)
                    <img
                        src="{{ $adminAvatarUrl }}"
                        alt="Avatar {{ $adminUser->name ?? 'Admin BangDeliv' }}"
                        loading="lazy"
                        onerror="this.parentElement.classList.remove('has-image'); this.remove();"
                    >
                @endif
                <i class="bx bxs-user" aria-hidden="true"></i>
            </div>
            <div class="user-text">
                <span class="user-name">{{ $adminUser->name ?? 'Admin BangDeliv' }}</span>
                <span class="user-role">Super Admin</span>
            </div>

            <form action="{{ route('logout') }}" method="POST" class="logout-form">
                @csrf
                <button type="submit" class="icon-btn is-danger" title="Keluar" aria-label="Keluar">
                    <i class="bx bx-log-out" aria-hidden="true"></i>
                </button>
            </form>

            <button onclick="toggleTheme()" class="icon-btn" title="Ganti tema" aria-label="Ganti tema">
                <i class="bx bx-moon" id="theme-btn-icon" aria-hidden="true"></i>
            </button>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-navbar">
            <div class="breadcrumb">
                <span>BangDeliv</span> / @yield('page-title', 'Dashboard')
            </div>

            <div class="nav-actions">
                <div class="notification-menu" data-notifications-url="{{ route('admin.notifications.pending') }}">
                    <button
                        id="notificationButton"
                        class="icon-btn"
                        type="button"
                        aria-label="Buka notifikasi admin"
                        aria-haspopup="true"
                        aria-expanded="false"
                    >
                        <i class="bx bx-bell" aria-hidden="true"></i>
                        <span id="notificationDot" class="notification-dot {{ ($adminNotificationSummary['total_pending'] ?? 0) > 0 ? '' : 'is-hidden' }}"></span>
                    </button>
                    <div id="notificationDropdown" class="notification-dropdown" hidden>
                        <div class="notification-header">
                            <div>
                                <span class="notification-title">Notifikasi</span>
                                <small id="notificationSubtitle">{{ $adminNotificationSummary['total_pending'] ?? 0 }} perlu ditinjau</small>
                            </div>
                        </div>
                        <div id="notificationList" class="notification-list">
                            @forelse(($adminNotificationSummary['items'] ?? []) as $item)
                                <a href="{{ $item['url'] }}" class="notification-item">
                                    <span class="notification-icon"><i class="bx {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                    <span>
                                        <small>{{ $item['label'] }}</small>
                                        <strong>{{ $item['title'] }}</strong>
                                        <em>{{ $item['description'] }}</em>
                                    </span>
                                </a>
                            @empty
                                <div class="notification-empty">Tidak ada verifikasi pending.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
            @if(session('success'))
                <div class="alert alert-success" role="status">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script>
    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        updateThemeIcon(next);
    }

    function updateThemeIcon(theme) {
        const icon = document.getElementById('theme-btn-icon');
        if (icon) {
            icon.className = theme === 'dark' ? 'bx bx-moon' : 'bx bx-sun';
        }
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function notificationItemTemplate(item) {
        return `
            <a href="${escapeHtml(item.url)}" class="notification-item">
                <span class="notification-icon"><i class="bx ${escapeHtml(item.icon)}" aria-hidden="true"></i></span>
                <span>
                    <small>${escapeHtml(item.label)}</small>
                    <strong>${escapeHtml(item.title)}</strong>
                    <em>${escapeHtml(item.description)}</em>
                </span>
            </a>
        `;
    }

    async function refreshAdminNotifications() {
        const menu = document.querySelector('.notification-menu');
        if (! menu) return;

        const response = await fetch(menu.dataset.notificationsUrl, {
            headers: { 'Accept': 'application/json' },
        });
        if (! response.ok) return;

        const payload = await response.json();
        const summary = payload.data || {};
        const total = Number(summary.total_pending || 0);
        const dot = document.getElementById('notificationDot');
        const subtitle = document.getElementById('notificationSubtitle');
        const list = document.getElementById('notificationList');

        dot?.classList.toggle('is-hidden', total <= 0);
        if (subtitle) subtitle.textContent = `${total} perlu ditinjau`;
        if (list) {
            const items = Array.isArray(summary.items) ? summary.items : [];
            list.innerHTML = items.length
                ? items.map(notificationItemTemplate).join('')
                : '<div class="notification-empty">Tidak ada verifikasi pending.</div>';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const saved = localStorage.getItem('theme') || 'light';
        updateThemeIcon(saved);

        const button = document.getElementById('notificationButton');
        const dropdown = document.getElementById('notificationDropdown');
        button?.addEventListener('click', () => {
            const isOpen = ! dropdown.hidden;
            dropdown.hidden = isOpen;
            button.setAttribute('aria-expanded', String(! isOpen));
        });

        document.addEventListener('click', (event) => {
            if (! dropdown || dropdown.hidden) return;
            if (! event.target.closest('.notification-menu')) {
                dropdown.hidden = true;
                button?.setAttribute('aria-expanded', 'false');
            }
        });

        const searchInputs = document.querySelectorAll('.search-bar input[name="q"], .search-bar input[name="search"]');
        searchInputs.forEach(input => {
            const form = input.closest('form');
            if (! form) return;

            let timeout = null;
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    const val = this.value.trim();
                    if (val.length >= 2 || val.length === 0) {
                        form.submit();
                    }
                }, 600);
            });
        });

        if (window.Echo) {
            window.Echo.channel('admin.notifications')
                .listen('.admin.notifications.updated', refreshAdminNotifications);
        }
    });
</script>
@stack('scripts')
</body>
</html>
