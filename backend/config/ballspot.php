<?php

return [
    'support_email' => env('BALLSPOT_SUPPORT_EMAIL', 'support@ballspot.app'),
    'web_url'       => env('BALLSPOT_WEB_URL', env('APP_URL', 'http://localhost')),

    // User-facing brand name for emails/notifications (config('app.name') stays
    // the framework name). Kept configurable as the product is renamed.
    'app_name'      => env('BALLSPOT_APP_NAME', 'BallPicker'),

    /*
    |--------------------------------------------------------------------------
    | Email two-factor login
    |--------------------------------------------------------------------------
    | After a correct email/password, a one-time 6-digit code is emailed and
    | must be verified before any API token is issued. Codes are stored hashed,
    | expire, and are attempt-limited. Resend is cooldown-limited.
    */
    /*
    |--------------------------------------------------------------------------
    | Player rank / level / XP progression
    |--------------------------------------------------------------------------
    | Personal long-term progression (distinct from leaderboard position). XP
    | currently equals a player's lifetime score total (tournament + daily
    | guesses). Ranks are ordered by min_xp ascending; the last is the max rank.
    */
    'ranks' => [
        ['name' => 'Rookie',      'level' => 1, 'min_xp' => 0],
        ['name' => 'Amateur',     'level' => 2, 'min_xp' => 2500],
        ['name' => 'Pro',         'level' => 3, 'min_xp' => 10000],
        ['name' => 'Elite',       'level' => 4, 'min_xp' => 25000],
        ['name' => 'Legend',      'level' => 5, 'min_xp' => 50000],
        ['name' => 'Ball Master', 'level' => 6, 'min_xp' => 100000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring convention
    |--------------------------------------------------------------------------
    | A guess score runs 0..max_score (distance-based, see ScoreService). These
    | are the single source of truth for "perfect" / "almost perfect" so the
    | thresholds are never scattered across controllers. A perfect pick equals
    | max_score; an almost-perfect pick is >= almost_perfect_threshold.
    */
    'scoring' => [
        'max_score'                => (int) env('BALLPICKER_MAX_SCORE', 100),
        'almost_perfect_threshold' => (int) env('BALLPICKER_ALMOST_PERFECT_THRESHOLD', 95),
    ],

    /*
    |--------------------------------------------------------------------------
    | XP awards (ledger source amounts)
    |--------------------------------------------------------------------------
    | Guess XP equals the guess score. These are the BONUS awards on top.
    */
    'xp' => [
        'badge' => [
            'common'    => 100,
            'rare'      => 250,
            'epic'      => 500,
            'legendary' => 1000,
            'default'   => 100,
        ],
        // Daily-streak milestone (days => bonus XP), awarded once per milestone.
        'streak' => [
            3  => 150,
            7  => 500,
            30 => 2500,
        ],
        // Tournament finish bonuses (rank => XP). Awarded on completion when
        // winner logic is available (see docs — MVP may not award these yet).
        'tournament_win' => [
            1 => 1000,
            2 => 500,
            3 => 250,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-sport onboarding taglines ("guess the …")
    |--------------------------------------------------------------------------
    */
    'sport_taglines' => [
        'football'          => 'Guess the ball',
        'tennis'            => 'Find the tennis ball',
        'golf'              => 'Spot the golf ball',
        'hockey'            => 'Find the puck',
        'cricket'           => 'Spot the cricket ball',
        'american_football' => 'Find the ball',
        'basketball'        => 'Spot the ball',
    ],

    /*
    |--------------------------------------------------------------------------
    | Second-sport readiness thresholds
    |--------------------------------------------------------------------------
    | Guidance for moving a sport from coming_soon to active. Not hard-enforced
    | — admin sees a warning; activation is still allowed.
    */
    'sport_readiness' => [
        'min_ready_challenges' => (int) env('BALLPICKER_SPORT_MIN_READY_CHALLENGES', 5),
        'min_scheduled_dailies' => (int) env('BALLPICKER_SPORT_MIN_SCHEDULED_DAILIES', 1),
    ],

    'auth' => [
        'login_code_expiry_minutes'          => (int) env('BALLSPOT_LOGIN_CODE_EXPIRY_MINUTES', 10),
        'login_code_max_attempts'            => (int) env('BALLSPOT_LOGIN_CODE_MAX_ATTEMPTS', 5),
        'login_code_resend_cooldown_seconds' => (int) env('BALLSPOT_LOGIN_CODE_RESEND_COOLDOWN_SECONDS', 60),

        // Require email verification at registration before full app access.
        'require_email_verification'         => (bool) env('BALLPICKER_REQUIRE_EMAIL_VERIFICATION', true),
        'email_code_expiry_minutes'          => (int) env('BALLPICKER_EMAIL_CODE_EXPIRY_MINUTES', 60),

        // Force the 6-digit code on EVERY normal login. Off by default now that
        // accounts are email-verified at registration. Admins always get 2FA.
        'force_login_2fa'                    => (bool) env('BALLPICKER_FORCE_LOGIN_2FA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | App themes
    |--------------------------------------------------------------------------
    | Extensible allow-list of theme tokens the mobile app can select. The
    | backend only validates/persists the identifier — the actual colors live
    | in the mobile theme system (mobile/src/theme/themes.ts). To add a theme,
    | append its slug here and define its palette in the app. "tournament_blue"
    | is original broadcast-inspired styling; it is NOT UEFA branding.
    */
    'themes' => [
        'classic',
        'tournament_blue',
        'pitch_green',
        'sunset_orange',
        'high_contrast',
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatar uploads
    |--------------------------------------------------------------------------
    | Profile photos are stored on the public disk. SVG is intentionally
    | excluded (scriptable). Max size mirrors a conservative mobile limit.
    */
    'avatar' => [
        'disk'        => 'public',
        'directory'   => 'avatars',
        'max_kb'      => (int) env('BALLSPOT_AVATAR_MAX_KB', 2048),
        'mimes'       => ['jpeg', 'jpg', 'png', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tournament limits (free tier)
    |--------------------------------------------------------------------------
    | Foundation only — NO payments or in-app purchases are implemented. Premium
    | limits are placeholders so a future premium tier can be enabled without a
    | schema/logic rewrite. With no premium system, every user is on the free
    | tier. Only lobby/active tournaments count toward the created limit;
    | archived/completed/cancelled tournaments do not.
    */
    'tournaments' => [
        'max_created_per_user'         => (int) env('BALLSPOT_MAX_CREATED_TOURNAMENTS', 3),
        'max_players_per_tournament'   => (int) env('BALLSPOT_MAX_PLAYERS_PER_TOURNAMENT', 8),

        // Premium placeholders (not enforced yet — no billing exists).
        'premium_max_created_per_user'       => (int) env('BALLSPOT_PREMIUM_MAX_CREATED_TOURNAMENTS', 20),
        'premium_max_players_per_tournament' => (int) env('BALLSPOT_PREMIUM_MAX_PLAYERS_PER_TOURNAMENT', 32),
    ],
];
