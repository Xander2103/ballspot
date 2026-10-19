<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

/**
 * A predictable BallPicker Clubs failure with a stable code and HTTP status
 * (docs/clubs-implementation-contracts.md §3.3).
 *
 *   throw new ClubDomainException('club_code_invalid', 422);
 *
 * Rendered by the hook in bootstrap/app.php:
 *  - API (api/* or expects JSON): { "message": "...", "code": "<code>", ...extra }
 *    with the given status. The app maps `code`; `message` is translated
 *    from lang/<locale>/clubs.php `errors.<code>` and safe to show.
 *  - admin / club portal (web): redirect back with the `error` flash + input.
 *
 * Never put emails, invite codes or raw exception text into `extra`.
 * Field validation stays Laravel's 422 {message, errors}.
 */
class ClubDomainException extends \RuntimeException
{
    // Codes used across the sprint (contracts §3.3 + Foundation additions).
    public const FEATURE_DISABLED                = 'feature_disabled';
    public const CLUB_NOT_FOUND                  = 'club_not_found';
    public const CLUB_FORBIDDEN                  = 'club_forbidden';
    public const CLUB_CODE_INVALID               = 'club_code_invalid';
    public const CLUB_JOIN_LOCKED                = 'club_join_locked';
    public const CLUB_FULL                       = 'club_full';
    public const CLUB_MEMBERSHIP_LIMIT           = 'club_membership_limit';
    public const CLUB_UNAVAILABLE                = 'club_unavailable';
    public const CLUB_OWNER_MUST_TRANSFER        = 'club_owner_must_transfer';
    public const CLUB_LAST_OWNER                 = 'club_last_owner';
    public const CLUB_HAS_HISTORY                = 'club_has_history';
    public const CLUB_CODE_LIMIT                 = 'club_code_limit';
    public const CLUB_STATUS_INVALID             = 'club_status_invalid';
    public const CLUB_MEMBER_NOT_FOUND           = 'club_member_not_found';
    public const CLUB_MEMBER_BANNED              = 'club_member_banned';
    public const STALE_VERSION                   = 'stale_version';
    public const PACK_RESERVED_FOR_COMPETITION   = 'pack_reserved_for_competition';
    public const COMPETITION_NOT_STARTED         = 'competition_not_started';
    public const COMPETITION_ENDED               = 'competition_ended';
    public const COMPETITION_ATTEMPT_LIMIT       = 'competition_attempt_limit';
    public const COMPETITION_PACK_ALREADY_PLAYED = 'competition_pack_already_played';
    public const COMPETITION_ATTEMPT_IN_PROGRESS = 'competition_attempt_in_progress';
    public const COMPETITION_RULES_LOCKED        = 'competition_rules_locked';
    public const COMPETITION_INVALID_PACK        = 'competition_invalid_pack';
    public const COMPETITION_NOT_EDITABLE        = 'competition_not_editable';
    public const UNEXPECTED_CHALLENGE            = 'unexpected_challenge';
    public const ATTEMPT_NOT_ACTIVE              = 'attempt_not_active';
    public const PHOTO_INVALID                   = 'photo_invalid';
    public const PHOTO_DUPLICATE                 = 'photo_duplicate';
    public const PORTAL_TWO_FACTOR_REQUIRED      = 'portal_two_factor_required';

    private string $errorCode;
    private int $status;
    private array $extra;

    public function __construct(string $code, int $status, array $extra = [])
    {
        $this->errorCode = $code;
        $this->status    = $status;
        $this->extra     = $extra;

        parent::__construct(self::translate($code));
    }

    /** Stable snake_case code (Exception::getCode() is the unrelated int code). */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function extra(): array
    {
        return $this->extra;
    }

    /** Translated sentence for a code in the current locale. */
    public static function translate(string $code): string
    {
        $key = 'clubs.errors.' . $code;

        return Lang::has($key) ? (string) __($key) : (string) __('clubs.errors.unknown');
    }

    // Shorthands for the most common throws ------------------------------

    /** Outsiders, non-members and anything that must not be confirmed to exist (404). */
    public static function notFound(): self
    {
        return new self(self::CLUB_NOT_FOUND, 404);
    }

    /** Member without the ability, e.g. member list hidden (403). */
    public static function forbidden(): self
    {
        return new self(self::CLUB_FORBIDDEN, 403);
    }

    /** Suspended club: read-only (423). */
    public static function unavailable(): self
    {
        return new self(self::CLUB_UNAVAILABLE, 423);
    }

    // Rendering ----------------------------------------------------------

    public function toJsonResponse(): JsonResponse
    {
        $headers = [];
        if (isset($this->extra['retry_after'])) {
            $headers['Retry-After'] = (int) $this->extra['retry_after'];
        }

        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra,
            $this->status,
            $headers,
        );
    }

    public function toRedirectResponse(): RedirectResponse
    {
        // Never flash secrets back into the session (same idea as the
        // framework's dontFlash list for validation errors).
        return back()->with('error', $this->getMessage())
            ->withInput(request()->except(['password', 'password_confirmation', 'current_password', 'code']));
    }

    /** API → JSON; admin / portal → back with the error flash. */
    public function toResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->toJsonResponse();
        }

        return $this->toRedirectResponse();
    }
}
