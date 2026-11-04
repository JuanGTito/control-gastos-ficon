<?php

namespace App\Modules\Identity\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function create()
    {
        return view('identity.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string|max:255']);
        $key = Str::lower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Demasiados intentos. Inténtalo en '.RateLimiter::availableIn($key).' segundos.']);
        }
        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        if (! Auth::attempt([$field => Str::lower(trim($data['login'])), 'password' => $data['password'], 'active' => true])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'El usuario o la contraseña no son correctos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('expenses.index'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
