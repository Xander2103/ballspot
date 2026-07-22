<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LoginVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        $token = $user->createToken('mobile')->plainTextToken;
        return response()->json(['user' => new UserResource($user), 'token' => $token], 201);
    }

    /**
     * POST /api/login
     *
     * Step 1 of email two-factor login. On valid credentials we do NOT issue a
     * token — instead a one-time code is emailed and the client must complete
     * POST /api/login/verify. Invalid credentials return a single generic error
     * (no user enumeration) and never trigger an email.
     */
    public function login(Request $request, LoginVerificationService $verification)
    {
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

        $record = $verification->start($user, $request->ip(), $request->userAgent());

        return response()->json([
            'requires_2fa'    => true,
            'verification_id' => $record->verification_id,
            'message'         => 'We sent a verification code to your email.',
        ]);
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
