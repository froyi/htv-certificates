<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SimpleAuth
{
    /**
     * Ensure the user is authenticated via session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('simpleauth.logged_in', false)) {
            return redirect()->route('login')->with('error', 'Bitte melden Sie sich an.');
        }

        return $next($request);
    }
}
