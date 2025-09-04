<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $expectedUsername = (string) Config::get('simpleauth.username');
        $expectedPassword = (string) Config::get('simpleauth.password');

        $username = (string) $request->input('username');
        $password = (string) $request->input('password');

        if ($username === $expectedUsername && $password === $expectedPassword) {
            Session::put('simpleauth.logged_in', true);

            return redirect()->route('upload.form');
        }

        return back()->withInput($request->only('username'))
            ->withErrors(['username' => 'Ungültige Zugangsdaten.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('simpleauth.logged_in');

        return redirect()->route('login')->with('status', 'Abgemeldet.');
    }
}
