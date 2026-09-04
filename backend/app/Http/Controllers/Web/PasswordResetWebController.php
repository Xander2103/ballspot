<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetFlow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Web fallback for the password reset flow.
 *
 * The reset email links to {FRONTEND_URL}/reset-password?token=…&email=…. In
 * production that is this backend's own domain, so the page must exist here:
 * a small form that completes the reset in the browser (works on any device,
 * no app install/deep-link support required). It also offers the
 * ballpicker:// deep link so a phone with the app installed can hand the
 * token straight to the in-app reset screen.
 */
class PasswordResetWebController extends Controller
{
    public function __construct(private PasswordResetFlow $flow) {}

    public function showReset(Request $request): Response
    {
        return $this->noStore(view('public.reset-password', [
            'token'    => (string) $request->query('token', ''),
            'email'    => (string) $request->query('email', ''),
            'deepLink' => $this->deepLink($request->query('token'), $request->query('email')),
        ]));
    }

    public function reset(ResetPasswordRequest $request): Response
    {
        $ok = $this->flow->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            'web',
        );

        $view = view('public.reset-password-result', [
            'ok'      => $ok,
            'message' => $ok ? 'Password updated' : PasswordResetFlow::INVALID_LINK_MESSAGE,
        ]);

        return $this->noStore($view, $ok ? 200 : 422);
    }

    public function showForgot(): Response
    {
        return $this->noStore(view('public.forgot-password', ['sent' => false]));
    }

    public function forgot(ForgotPasswordRequest $request): Response
    {
        $this->flow->request((string) $request->input('email'), 'web');

        // Identical page whatever happened — no enumeration.
        return $this->noStore(view('public.forgot-password', ['sent' => true]));
    }

    /** The in-app route the mobile app registers for the ballpicker:// scheme. */
    private function deepLink(?string $token, ?string $email): ?string
    {
        if (!$token || !$email) {
            return null;
        }

        return 'ballpicker://reset-password?token=' . rawurlencode($token) . '&email=' . rawurlencode($email);
    }

    /**
     * Reset pages carry a token in the URL/form: never cache them. (Referrer
     * leakage is covered by the global SecurityHeaders middleware.)
     */
    private function noStore(View $view, int $status = 200): Response
    {
        return response($view->render(), $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
