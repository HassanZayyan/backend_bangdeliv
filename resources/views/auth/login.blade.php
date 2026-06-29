@extends('layouts.auth')

@section('title', 'Login - Admin Pelanggan 15')

@section('content')
<div class="auth-shell">
    <section class="auth-brand-panel" aria-labelledby="auth-brand-title">
        <img src="{{ asset('images/logo.jpg') }}" alt="Logo Pelanggan 15" class="auth-brand-logo">
        <div class="auth-brand-copy">
            <span>Pelanggan 15</span>
            <h1 id="auth-brand-title">Admin Panel</h1>
            <p>Masuk untuk mengelola operasional Pelanggan 15.</p>
        </div>
    </section>

    <div class="auth-divider" aria-hidden="true"></div>

    <section class="auth-card" aria-labelledby="login-heading">
        <div class="auth-logo">
            <span id="login-heading">Selamat Datang</span>
            <small>Silakan login ke dashboard admin.</small>
        </div>

        <form action="{{ route('login') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="email">Alamat Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="admin@bangdeliv.com" autocomplete="username" required value="{{ old('email') }}">
                @error('email')
                    <div class="form-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" class="form-control" placeholder="Kata sandi" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" aria-label="Tampilkan kata sandi" aria-pressed="false" data-password-toggle>
                        <i class="bx bx-show" aria-hidden="true"></i>
                    </button>
                </div>
                @error('password')
                    <div class="form-error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="auth-footnote">
            <p>Sistem ini terbatas hanya untuk staf internal Pelanggan 15.</p>
            <button type="button" onclick="toggleTheme()" class="btn auth-theme-btn">
                <i class='bx bx-moon' id="theme-icon" aria-hidden="true"></i>
                Ganti Tema
            </button>
        </div>
    </section>
</div>

<script>
    function toggleTheme() {
        const body = document.body;
        const currentTheme = body.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        body.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);

        const icon = document.getElementById('theme-icon');
        icon.className = newTheme === 'dark' ? 'bx bx-moon' : 'bx bx-sun';
    }

    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme') || 'dark';
        const icon = document.getElementById('theme-icon');
        icon.className = savedTheme === 'dark' ? 'bx bx-moon' : 'bx bx-sun';

        const passwordInput = document.getElementById('password');
        const passwordToggle = document.querySelector('[data-password-toggle]');

        if (passwordInput && passwordToggle) {
            passwordToggle.addEventListener('click', () => {
                const shouldShow = passwordInput.type === 'password';

                passwordInput.type = shouldShow ? 'text' : 'password';
                passwordToggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
                passwordToggle.setAttribute('aria-label', shouldShow ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                passwordToggle.querySelector('i').className = shouldShow ? 'bx bx-hide' : 'bx bx-show';
            });
        }
    });
</script>
@endsection
