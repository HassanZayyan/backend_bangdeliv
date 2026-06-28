<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Login - Admin BangDeliv')</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <link rel="shortcut icon" type="image/jpeg" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <link rel="apple-touch-icon" href="{{ asset('images/logo.jpg') }}?v=bangdeliv">
    <!-- CSS Internal/Custom -->
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <!-- Boxicons for Icons -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body data-theme="dark"> <!-- Default to dark mode based on UI ref -->
    <div class="auth-layout">
        @yield('content')
    </div>

    <!-- Script to toggle dark/light mode based on localstorage (Optional enhancement) -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.body.setAttribute('data-theme', savedTheme);
        });
    </script>
</body>
</html>
