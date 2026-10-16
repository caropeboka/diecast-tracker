<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /** Visual styles from Settings → Appearance; the first is the default. */
    public const STYLES = ['garage', 'showroom', 'neon'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $style = $request->cookie('style');

        View::share('appearance', $request->cookie('appearance') ?? 'dark');
        View::share('style', in_array($style, self::STYLES, true) ? $style : self::STYLES[0]);

        return $next($request);
    }
}
