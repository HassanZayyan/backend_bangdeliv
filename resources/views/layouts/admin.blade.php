<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Dashboard - BangDeliv')</title>
    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <!-- Boxicons (Icon Library as requested) -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    {{-- 
        Anti-FOIT: Script ini berjalan SINKRON (blocking) sebelum browser
        mulai paint apapun, sehingga tema yang benar langsung teraplikasi
        tanpa Flash of Incorrect Theme.
    --}}
    <script>
        (function() {
            var saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>
<body>

    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            @php
                $sidebarOrderCount = \App\Models\Order::count();
                $sidebarDriverCount = \App\Models\Driver::count();
                $sidebarVerificationCount = \App\Models\Driver::where('registration_status', 'pending')->count();
                $serviceTypeIdMap = \App\Models\ServiceType::query()->pluck('id', 'code');
                $sidebarShoppingCount = isset($serviceTypeIdMap['SHOPPING'])
                    ? \App\Models\Order::where('service_type_id', $serviceTypeIdMap['SHOPPING'])->count()
                    : 0;
                $sidebarCourierCount = isset($serviceTypeIdMap['COURIER'])
                    ? \App\Models\Order::where('service_type_id', $serviceTypeIdMap['COURIER'])->count()
                    : 0;
                $sidebarRideCount = isset($serviceTypeIdMap['RIDE'])
                    ? \App\Models\Order::where('service_type_id', $serviceTypeIdMap['RIDE'])->count()
                    : 0;
                $isOrdersRoute = Request::routeIs('admin.orders.*');
                $orderServiceFilter = request()->query('service', 'all');
                if (!in_array($orderServiceFilter, ['all', 'shopping', 'courier', 'ride'], true)) {
                    $orderServiceFilter = 'all';
                }
            @endphp
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="{{ asset('images/logo.jpg') }}" alt="BangDeliv">
                </div>
                <div style="display:flex; flex-direction:column; line-height:1.1;">
                    <span>BangDeliv</span>
                    <small style="color:var(--text-muted); font-size:10px; margin-top:2px;">Admin Panel</small>
                </div>
            </div>

            <div class="sidebar-menu">
                <div class="menu-category">UTAMA</div>
                <a href="{{ route('admin.dashboard') }}" class="menu-item {{ Request::routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class='bx bxs-dashboard'></i>
                    Dashboard
                </a>

                <div class="menu-category">OPERASIONAL</div>
                <div class="menu-group">
                    <a href="{{ route('admin.orders.index', ['service' => 'all']) }}" class="menu-item {{ $isOrdersRoute ? 'active' : '' }}">
                        <i class='bx bx-receipt'></i>
                        Pesanan
                        @if($sidebarOrderCount > 0)
                            <span class="menu-badge">{{ $sidebarOrderCount }}</span>
                        @endif
                    </a>
                    <div class="menu-submenu">
                        <a href="{{ route('admin.orders.index', ['service' => 'all']) }}" class="menu-subitem {{ $isOrdersRoute && $orderServiceFilter === 'all' ? 'active' : '' }}">
                            Semua Layanan
                        </a>
                        <a href="{{ route('admin.orders.index', ['service' => 'shopping']) }}" class="menu-subitem {{ $isOrdersRoute && $orderServiceFilter === 'shopping' ? 'active' : '' }}">
                            Titip Belanja
                            <span style="margin-left:auto; font-size:11px; color:var(--text-muted);">{{ $sidebarShoppingCount }}</span>
                        </a>
                        <a href="{{ route('admin.orders.index', ['service' => 'courier']) }}" class="menu-subitem {{ $isOrdersRoute && $orderServiceFilter === 'courier' ? 'active' : '' }}">
                            Kurir
                            <span style="margin-left:auto; font-size:11px; color:var(--text-muted);">{{ $sidebarCourierCount }}</span>
                        </a>
                        <a href="{{ route('admin.orders.index', ['service' => 'ride']) }}" class="menu-subitem {{ $isOrdersRoute && $orderServiceFilter === 'ride' ? 'active' : '' }}">
                            Antar Jemput
                            <span style="margin-left:auto; font-size:11px; color:var(--text-muted);">{{ $sidebarRideCount }}</span>
                        </a>
                    </div>
                </div>
                <a href="{{ route('admin.drivers.index') }}" class="menu-item {{ Request::routeIs('admin.drivers.index', 'admin.drivers.show') ? 'active' : '' }}">
                    <i class='bx bx-cycling'></i>
                    Driver
                    @if($sidebarDriverCount > 0)
                        <span class="menu-badge" style="background:none;color:var(--color-success)">{{ $sidebarDriverCount }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.customers.index') }}" class="menu-item {{ Request::routeIs('admin.customers.*') ? 'active' : '' }}">
                    <i class='bx bxs-group'></i>
                    Pelanggan
                </a>
                <a href="{{ route('admin.restaurants.index') }}" class="menu-item {{ Request::routeIs('admin.restaurants.*') ? 'active' : '' }}">
                    <i class='bx bx-store'></i>
                    Restoran / Warung
                </a>

                <div class="menu-category">SISTEM</div>
                <a href="{{ route('admin.verification') }}" class="menu-item {{ Request::routeIs('admin.verification*') ? 'active' : '' }}">
                    <i class='bx bx-check-shield'></i>
                    Verifikasi Driver
                    @if($sidebarVerificationCount > 0)
                        <span class="menu-badge" style="background:var(--color-warning); color:white;">{{ $sidebarVerificationCount }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.settings') }}" class="menu-item {{ Request::routeIs('admin.settings') ? 'active' : '' }}">
                    <i class='bx bx-cog'></i>
                    Pengaturan
                </a>
            </div>

            <div class="sidebar-footer">
                <div class="user-avatar">
                    <i class='bx bxs-user'></i>
                </div>
                <div class="user-text">
                    <span class="user-name">{{ Auth::user()->name ?? 'Admin BangDeliv' }}</span>
                    <span class="user-role" style="color:var(--color-primary)">Super Admin</span>
                </div>
                
                <form action="{{ route('logout') }}" method="POST" style="margin-left: auto;">
                    @csrf
                    <button type="submit" class="icon-btn" title="Keluar / Logout" style="border: none; background: none; cursor: pointer; color: #ff4d4d;">
                        <i class='bx bx-log-out' ></i>
                    </button>
                </form>

                <button onclick="toggleTheme()" class="icon-btn" style="margin-left: 5px;" title="Ganti Tema Gelap/Terang">
                    <i class='bx bx-moon' id="theme-btn-icon"></i>
                </button>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Top Navbar -->
            <header class="top-navbar">
                <div class="breadcrumb">
                    <span>BangDeliv</span> / @yield('page-title', 'Dashboard')
                </div>
                
                <div class="nav-actions">

                    <button class="icon-btn" style="position:relative">
                        <i class='bx bx-bell'></i>
                        <span style="position:absolute; top:8px; right:8px; width:8px; height:8px; background:var(--color-primary); border-radius:50%"></span>
                    </button>
                    

                </div>
            </header>

            <!-- Page Content -->
            <div class="content-wrapper">
                @yield('content')
            </div>
        </main>
    </div>

    <!-- Theme Persist Script -->
    <script>
        function toggleTheme() {
            // Baca dari documentElement (html tag) karena theme diterapkan di sana
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

        // Sinkronkan ikon saat halaman selesai dimuat
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('theme') || 'light';
            updateThemeIcon(saved);

            // Auto-submit search forms
            const searchInputs = document.querySelectorAll('.search-bar input[name="q"], .search-bar input[name="search"]');
            searchInputs.forEach(input => {
                const form = input.closest('form');
                if(form) {
                    form.addEventListener('submit', () => {
                        sessionStorage.setItem('autoSearchTriggered', 'true');
                    });
                }
                
                let timeout = null;
                input.addEventListener('input', function() {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        const val = this.value.trim();
                        if (val.length >= 2 || val.length === 0) {
                            sessionStorage.setItem('autoSearchTriggered', 'true');
                            form.submit();
                        }
                    }, 600); // 600ms debounce
                });
                
                if (sessionStorage.getItem('autoSearchTriggered') === 'true' && document.activeElement !== input) {
                    input.focus();
                    const val = input.value;
                    input.value = '';
                    input.value = val;
                    sessionStorage.removeItem('autoSearchTriggered');
                }
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
