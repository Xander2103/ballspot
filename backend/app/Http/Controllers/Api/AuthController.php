<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\LoginVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/register
     *
     * Creates an unverified account, issues a token (so the app can complete
     * verification and read /me), and emails a 6-digit verification code.
     * Full app endpoints stay gated behind the `verified` middleware until the
     * email is verified.
     */
    public function register(RegisterRequest $request, EmailVerificationService $emailVerification)
    {
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if ($this->emailVerificationRequired()) {
            $emailVerification->send($user, $request->ip(), $request->userAgent(), force: true);
        } else {
            // Verification disabled by config — treat new accounts as verified.
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user'           => new UserResource($user),
            'token'          => $token,
            'email_verified' => $user->hasVerifiedEmail(),
        ], 201);
    }

    /**
     * POST /api/login
     *
     * With a verified email this returns a token directly (email + password is
     * enough for normal play). Two extra paths:
     *  - Unverified email  -> requires_email_verification (a code is re-sent; a
     *    token is issued so the app can call the verify/resend endpoints).
     *  - Forced 2FA (config force_login_2fa, or any admin) -> requires_2fa and a
     *    login code is emailed; the token is only issued by /login/verify.
     * Invalid credentials return a single generic error (no user enumeration)
     * and never trigger an email.
     */
    public function login(
        Request $request,
        LoginVerificationService $login2fa,
        EmailVerificationService $emailVerification,
    ) {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Burn comparable time when the account is unknown so response
            // timing does not reveal whether the email exists.
            if (!$user) {
                Hash::make($request->password);
            }
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        // Email not verified yet — block full login, (re)send a code, and hand
        // back a token so the app can drive the verification screen.
        if ($this->emailVerificationRequired() && !$user->hasVerifiedEmail()) {
            $emailVerification->send($user, $request->ip(), $request->userAgent());
            $token = $user->createToken('mobile')->plainTextToken;

            return response()->json([
                'requires_email_verification' => true,
                'email_verified'              => false,
                'user'                        => new UserResource($user),
                'token'                       => $token,
                'message'                     => 'Please verify your email address to continue. We sent you a code.',
            ]);
        }

        // Optional/forced 2FA — always on for admins, otherwise config-driven.
        if ($this->twoFactorForced($user)) {
            $record = $login2fa->start($user, $request->ip(), $request->userAgent());

            return response()->json([
                'requires_2fa'    => true,
                'verification_id' => $record->verification_id,
                'message'         => 'We sent a verification code to your email.',
            ]);
        }

        // Normal login — email + password is enough.
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user'  => new UserResource($user),
            'token' => $token,
        ]);
    }

    private function emailVerificationRequired(): bool
    {
        return (bool) config('ballspot.auth.require_email_verification', true);
    }

    private function twoFactorForced(User $user): bool
    {
        return (bool) config('ballspot.auth.force_login_2fa', false) || (bool) $user->is_admin;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }
}
