<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'perm' => \App\Http\Middleware\CheckMenuPermission::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Token CSRF basi (tab lama terbuka, atau sesi kena regenerate) -> jangan tampilkan
        // halaman error 419 mentah, cukup balik ke halaman asal dengan pesan yang jelas.
        // GOTCHA: Handler::render() memetakan TokenMismatchException -> HttpException(419,...)
        // via prepareException() SEBELUM callback render() custom dicek, jadi type-hint harus
        // HttpException + cek getStatusCode(), bukan TokenMismatchException.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect()->back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->with('status', 'Sesi sudah kadaluarsa, silakan coba lagi.');
        });
    })->create();
