@extends('layouts.auth')

@section('title', 'Login - Admin BangDeliv')

@section('content')
<div class="auth-card">
    <div class="auth-logo">
        <i class='bx bxs-store-alt' style="color: var(--color-primary)"></i>
        <span>BangDeliv Admin</span>
    </div>
    
    <form action="{{ route('login') }}" method="POST">
        @csrf
        <div class="form-group">
            <label for="email">Alamat Email</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="admin@bangdeliv.com" required value="{{ old('email') }}">
            @error('email')
                <div style="color: #ff4d4f; font-size: 13px; margin-top: 5px;">{{ $message }}</div>
            @enderror
        </div>
        
        <div class="form-group" style="margin-bottom: 30px;">
            <label for="password">Kata Sandi</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        
        <button type="submit" class="btn btn-primary btn-block">Masuk ke Dashboard</button>
    </form>
    
    <div style="text-align: center; margin-top: 24px;">
        <p style="color: var(--text-muted); font-size: 13px;">Sistem ini terbatas hanya untuk staf Internal BangDeliv.</p>
        <button onclick="toggleTheme()" class="btn" style="background: none; border: none; color: var(--text-muted); margin: 15px auto 0;">
            <i class='bx bx-moon' id="theme-icon"></i> Ganti Tema
        </button>
    </div>
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

    // Set icon based on init
    document.addEventListener('DOMContentLoaded', () => {
        const savedTheme = localStorage.getItem('theme') || 'dark';
        const icon = document.getElementById('theme-icon');
        icon.className = savedTheme === 'dark' ? 'bx bx-moon' : 'bx bx-sun';
    });
</script>
@endsection
