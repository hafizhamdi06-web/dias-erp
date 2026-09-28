{{-- Layout halaman berdiri sendiri untuk user yang SUDAH login tapi belum boleh masuk
     aplikasi (mis. wajib ganti password). Tampilannya mengikuti auth/login.blade.php. --}}
<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Ganti Password' }} &middot; {{ config('app.name') }}</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('dias-theme')
                    || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.setAttribute('data-bs-theme', t);
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('vendor/adminlte/dist/css/adminlte.min.css') }}">
    <style>
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    </style>
    @livewireStyles
</head>
<body class="login-page bg-body-secondary d-flex align-items-center justify-content-center vh-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>
