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
            var saved = localStorage.getItem('theme') || 'dark';
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
            @endphp
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class='bx bx-store-alt'></i>
                </div>
                <span>BangDeliv</span>
                <small style="color:var(--text-muted); font-size:10px; margin-top:2px;">Admin Panel</small>
            </div>

            <div class="sidebar-menu">
                <div class="menu-category">UTAMA</div>
                <a href="{{ route('admin.dashboard') }}" class="menu-item {{ Request::routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class='bx bxs-dashboard'></i>
                    Dashboard
                </a>

                <div class="menu-category">OPERASIONAL</div>
                <a href="{{ route('admin.orders.index') }}" class="menu-item {{ Request::routeIs('admin.orders.*') ? 'active' : '' }}">
                    <i class='bx bx-receipt'></i>
                    Pesanan
                    @if($sidebarOrderCount > 0)
                        <span class="menu-badge">{{ $sidebarOrderCount }}</span>
                    @endif
                </a>
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
                <a href="{{ route('admin.verification') }}" class="menu-item {{ Request::routeIs('admin.verification') ? 'active' : '' }}">
                    <i class='bx bx-check-shield'></i>
                    Verifikasi Driver
                    @if($sidebarVerificationCount > 0)
                        <span class="menu-badge" style="background:var(--color-warning); color:white;">{{ $sidebarVerificationCount }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.ai-monitor') }}" class="menu-item {{ Request::routeIs('admin.ai-monitor') ? 'active' : '' }}">
                    <i class='bx bx-bot'></i>
                    AI Monitor
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
            const saved = localStorage.getItem('theme') || 'dark';
            updateThemeIcon(saved);
        });
    </script>
    @stack('scripts')
</body>
</html>
