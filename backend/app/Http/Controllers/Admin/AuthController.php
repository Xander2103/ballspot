<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->is_admin) {
            return redirect('/admin/challenges');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // No "remember me" for the admin panel. Laravel's recaller cookie lasts
        // ~400 days; on the account that can push to every device and manage all
        // content, a single exfiltrated cookie must not be durable access.
        // Sessions expire with SESSION_LIFETIME instead.
        if (Auth::attempt($credentials)) {
            if (!auth()->user()->is_admin) {
                Auth::logout();
                return back()->withErrors(['email' => 'Access denied.']);
            }

            // Match the API path, which refuses unverified accounts.
            if (!auth()->user()->hasVerifiedEmail()) {
                Auth::logout();
                return back()->withErrors(['email' => 'Verify your email address before signing in.']);
            }

            $request->session()->regenerate();
            return redirect('/admin/challenges');
        }

        return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
