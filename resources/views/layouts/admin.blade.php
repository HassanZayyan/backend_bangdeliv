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
</head>
<body data-theme="dark">

    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
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
                <a href="{{ route('admin.pesanan') }}" class="menu-item {{ Request::routeIs('admin.pesanan') ? 'active' : '' }}">
                    <i class='bx bx-receipt'></i>
                    Pesanan
                    <span class="menu-badge">24</span>
                </a>
                <a href="{{ route('admin.driver') }}" class="menu-item {{ Request::routeIs('admin.driver') ? 'active' : '' }}">
                    <i class='bx bx-cycling'></i>
                    Driver
                    <span class="menu-badge" style="background:none;color:var(--color-success)">8</span>
                </a>
                <a href="{{ route('admin.pelanggan') }}" class="menu-item {{ Request::routeIs('admin.pelanggan') ? 'active' : '' }}">
                    <i class='bx bxs-group'></i>
                    Pelanggan
                </a>

                <a href="{{ route('admin.restoran') }}" class="menu-item {{ Request::routeIs('admin.restoran') ? 'active' : '' }}">
                    <i class='bx bx-store'></i>
                    Restoran / Warung
                </a>

                <div class="menu-category">SISTEM</div>
                <a href="{{ route('admin.verifikasi-driver') }}" class="menu-item {{ Request::routeIs('admin.verifikasi-driver') ? 'active' : '' }}">
                    <i class='bx bx-check-shield'></i>
                    Verifikasi Driver
                    <span class="menu-badge" style="background:var(--color-warning); color:white;">3</span>
                </a>
                <a href="{{ route('admin.ai-monitor') }}" class="menu-item {{ Request::routeIs('admin.ai-monitor') ? 'active' : '' }}">
                    <i class='bx bx-bot'></i>
                    AI Monitor
                </a>

                <a href="{{ route('admin.pengaturan') }}" class="menu-item {{ Request::routeIs('admin.pengaturan') ? 'active' : '' }}">
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
            const body = document.body;
            const currentTheme = body.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            body.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        }

        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-btn-icon');
            if(icon) {
               icon.className = theme === 'dark' ? 'bx bx-moon' : 'bx bx-sun';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.body.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);
        });
    </script>
    @stack('scripts')
</body>
</html>
