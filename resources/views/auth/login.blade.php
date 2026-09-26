<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk &middot; {{ config('app.name') }}</title>
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
</head>
<body class="login-page bg-body-secondary d-flex align-items-center justify-content-center vh-100">
    <div class="login-box" style="width: 22rem;">
        <div class="card card-outline card-primary">
            <div class="card-header text-center">
                <span class="h4 fw-light">{{ config('app.name') }}</span>
            </div>
            <div class="card-body">
                <p class="login-box-msg">Masuk untuk memulai sesi</p>

                @if (session('status'))
                    <div class="alert alert-warning py-2">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger py-2">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="input-group mb-3">
                        <input type="text" name="ukode" value="{{ old('ukode') }}"
                               class="form-control" placeholder="Username" autofocus required>
                        <div class="input-group-text"><i class="fas fa-user"></i></div>
                    </div>
                    <div class="input-group mb-3">
                        <input type="password" name="password"
                               class="form-control" placeholder="Password" required>
                        <div class="input-group-text"><i class="fas fa-lock"></i></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-right-to-bracket me-1"></i> Masuk
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
