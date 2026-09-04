<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\LoginVerificationService;
use App\Support\AppLog;
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
     *
     * `code_sent` tells the app whether the email actually left the building —
     * a mail-transport failure must not turn into a dead verification screen,
     * so the account is still created and the user can tap "resend".
     */
    public function register(RegisterRequest $request, EmailVerificationService $emailVerification)
    {
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Record the consent moment so acceptance can be demonstrated later.
        // Set outside create() — these are deliberately not mass-assignable.
        $user->terms_accepted_at = now();
        $user->terms_version     = (string) config('ballspot.legal.terms_version', '2026-08');
        $user->save();

        $codeSent = false;
        if ($this->emailVerificationRequired()) {
            $codeSent = $emailVerification->send($user, $request->ip(), $request->userAgent(), force: true);
        } else {
            // Verification disabled by config — treat new accounts as verified.
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('mobile')->plainTextToken;

        AppLog::event('auth.registered', [
            'user_id'               => $user->id,
            'verification_required' => $this->emailVerificationRequired(),
            'code_sent'             => $codeSent,
            'beta_gate'             => (bool) config('ballspot.beta_code'),
        ]);

        return response()->json([
            'user'           => new UserResource($user),
            'token'          => $token,
            'email_verified' => $user->hasVerifiedEmail(),
            'code_sent'      => $codeSent,
        ], 201);
    }

    /**
     * POST /api/login
     *
     * With a verified email this returns a token directly (email + password is
     * enough for normal play). Two extra paths:
     *  - Unverified email  -> requires_email_verification. A token is issued so
     *    the app can call the verify/resend endpoints. A code is only (re)sent
     *    when the user has no usable one left — sending a new code on every
     *    login used to invalidate the one already sitting in their inbox.
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
            // Category only — never the email, never the password.
            AppLog::warn('auth.login_failed', [
                'reason'  => $user ? 'wrong_password' : 'unknown_account',
                'user_id' => $user?->id,
            ]);
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        // Email not verified yet — block full login and hand back a token so
        // the app can drive the verification screen.
        if ($this->emailVerificationRequired() && !$user->hasVerifiedEmail()) {
            $codeSent = false;
            if (!$emailVerification->hasUsableCode($user)) {
                $codeSent = $emailVerification->send($user, $request->ip(), $request->userAgent());
            }
            $token = $user->createToken('mobile')->plainTextToken;

            return response()->json([
                'requires_email_verification' => true,
                'email_verified'              => false,
                'code_sent'                   => $codeSent,
                'user'                        => new UserResource($user),
                'token'                       => $token,
                'message'                     => $codeSent
                    ? 'Please verify your email address to continue. We sent you a new code.'
                    : 'Please verify your email address to continue. Enter the code we emailed you, or request a new one.',
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
