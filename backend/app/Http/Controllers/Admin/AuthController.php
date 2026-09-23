<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppLog;
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

    /**
     * Admin panel sign-in. Every outcome is logged as a safe category
     * (`admin.login` / `admin.login_failed` with reason + user id) — never the
     * email or the password — so a brute-force run or a stolen password shows
     * up on /admin/diagnostics. Non-admin credentials get the same generic
     * copy as a wrong password: the form must not confirm which accounts are
     * admins.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Case-insensitive address match (see User::findByEmail), then the
        // normal credential check against the stored row.
        $user        = User::findByEmail((string) $request->input('email'));
        $credentials = ['email' => $user?->email ?? (string) $request->input('email'), 'password' => (string) $request->input('password')];

        // No "remember me" for the admin panel. Laravel's recaller cookie lasts
        // ~400 days; on the account that can push to every device and manage all
        // content, a single exfiltrated cookie must not be durable access.
        // Sessions expire with SESSION_LIFETIME instead.
        if (Auth::attempt($credentials)) {
            if (!auth()->user()->is_admin) {
                $id = auth()->id();
                Auth::logout();
                AppLog::warn('admin.login_failed', ['reason' => 'not_admin', 'user_id' => $id]);
                return back()->withErrors(['email' => 'Invalid credentials.']);
            }

            // Match the API path, which refuses unverified accounts.
            if (!auth()->user()->hasVerifiedEmail()) {
                $id = auth()->id();
                Auth::logout();
                AppLog::warn('admin.login_failed', ['reason' => 'unverified', 'user_id' => $id]);
                return back()->withErrors(['email' => 'Verify your email address before signing in.']);
            }

            $request->session()->regenerate();
            AppLog::event('admin.login', ['user_id' => auth()->id()]);
            return redirect('/admin/challenges');
        }

        AppLog::warn('admin.login_failed', [
            'reason'  => $user ? 'wrong_password' : 'unknown_account',
            'user_id' => $user?->id,
        ]);

        return back()->withErrors(['email' => 'Invalid credentials.'])->withInput();
    }

    public function logout(Request $request)
    {
        $id = auth()->id();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        AppLog::event('admin.logout', ['user_id' => $id]);
        return redirect()->route('admin.login');
    }
}
