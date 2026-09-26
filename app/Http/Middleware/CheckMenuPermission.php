<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi akses route berdasarkan hak menu user aktif.
 *
 * Pemakaian di route:  ->middleware('perm:view')  (ability default: view)
 * Path yang dicek = path URI request; dicocokkan ke lv_menu.route terpanjang.
 */
class CheckMenuPermission
{
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        $acl = app('acl');

        if (! $acl->check()) {
            return redirect()->route('login');
        }

        if (! $acl->canRoute($request->path(), $ability)) {
            abort(403, 'Anda tidak memiliki hak "' . $ability . '" untuk halaman ini.');
        }

        return $next($request);
    }
}
