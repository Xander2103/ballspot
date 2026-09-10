<?php

/*
|--------------------------------------------------------------------------
| API messages returned directly to the app (+ server push copy)
|--------------------------------------------------------------------------
| Machine-readable `code`/`reason` fields stay English and stable; only the
| human `message` is translated. Rendered under the requesting user's
| preferred_language (App\Http\Middleware\SetLocale).
*/

return [
    'rate_limited' => 'Too many requests. Please try again in :seconds seconds.',

    'auth' => [
        'logged_out'                   => 'Logged out',
        'verify_email_new_code'        => 'Please verify your email address to continue. We sent you a new code.',
        'verify_email_existing_code'   => 'Please verify your email address to continue. Enter the code we emailed you, or request a new one.',
        'email_verified'               => 'Your email has been verified.',
        'email_already_verified'       => 'Your email is already verified.',
        'verification_code_resent'     => 'A new verification code has been sent to your email.',
        'verification_code_required'   => 'Enter the 6-digit code from your email.',
        'verification_session_mismatch' => 'This code belongs to a different account than the one signed in on this device. Please log in again with the account you just created.',
        'resend_cooldown'              => 'Please wait a moment before requesting another code.',
        'resend_failed'                => 'We could not send the email right now. Please try again in a moment.',
        'login_code_resent'            => 'If your login is still pending, a new code has been sent to your email.',
        'login_again'                  => 'Please login again.',
        'reset_link_sent'              => 'If an account exists for that email, a password reset link has been sent.',
        'password_reset_done'          => 'Your password has been reset. Please log in.',
        'reset_link_invalid'           => 'This password reset link is invalid or has expired.',
        'beta_code_required'           => 'A beta code is required during closed testing.',
        'beta_code_invalid'            => 'Invalid beta code.',
        'password_min'                 => 'Password must be at least 8 characters.',
        'language_unsupported'         => 'Please choose a supported language.',
        'terms_required'               => 'You must accept the Terms of Service and Privacy Policy.',
        'age_required'                 => 'You must confirm you meet the minimum age requirement.',
    ],

    'account' => [
        'deleted'        => 'Your account has been deleted.',
        'delete_failed'  => 'We could not delete your account right now. Please try again in a moment or contact support.',
    ],

    'preferences' => [
        'sport_unavailable'  => 'This sport is not available yet.',
        'theme_unavailable'  => 'That theme is not available.',
        'two_factor_boolean' => 'Two-factor login must be on or off.',
    ],

    'daily' => [
        'not_active'      => 'This daily challenge is not active.',
        'not_today'       => 'This daily challenge is not available today.',
        'already_played'  => "You have already played today's challenge.",
        'no_guess'        => 'No guess found for this challenge.',
    ],

    'friends' => [
        'code_not_found'     => 'No player found with that friend code.',
        'self'               => 'You cannot add yourself as a friend.',
        'already_friends'    => 'You are already friends with this player.',
        'pending_exists'     => 'There is already a pending request with this player.',
        'declined'           => 'This player declined your request. You can try again later.',
        'not_addressed'      => 'This request is not addressed to you.',
        'not_pending'        => 'This request is no longer pending.',
        'rejected'           => 'Request rejected.',
        'not_friends'        => 'You are not friends with this player.',
    ],

    'tournaments' => [
        'not_member'         => 'Not a member of this league',
        'hide_only_finished' => 'Only finished tournaments can be removed from your list.',
        'owner_only_start'   => 'Only the owner can start this tournament.',
        'start_from_lobby'   => 'Tournament can only be started from lobby status.',
        'no_challenges'      => 'No active :sport challenges available. Add challenges in admin.',
        'owner_only_cancel'  => 'Only the owner can cancel this tournament.',
        'owner_only_remove'  => 'Only the owner can remove players.',
        'remove_in_lobby'    => 'Players can only be removed while the tournament is in lobby.',
        'owner_not_removable' => 'The owner cannot be removed.',
        'daily_limit'        => 'You have played all rounds available for today.',
        'not_enough_challenges' => 'Not enough unused tournament challenges available. Add more tournament photos first.',
        'temporarily_unavailable' => 'Tournaments are temporarily unavailable while we prepare new challenges. Please try again soon.',
        'temporarily_unavailable_next_month' => 'Tournaments are temporarily unavailable while we prepare new challenges. Please try again at the beginning of next month.',
    ],

    'packs' => [
        'already_completed' => 'You have already completed this pack.',
    ],

    'push' => [
        'daily_reminder_title' => 'Daily Challenge',
        'daily_reminder_body'  => "Today's :app daily is still waiting for you ⚽",
    ],
];
