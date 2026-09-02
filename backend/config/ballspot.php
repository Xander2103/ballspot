<?php

return [
    // ballspot.app is not a registered domain — the fallback must stay a real,
    // monitored mailbox or the legal pages advertise a dead address.
    'support_email' => env('BALLSPOT_SUPPORT_EMAIL', 'info@vanmalderstudio.be'),

    // Human-readable release label shown in the admin header, on
    // /admin/diagnostics and in operational logs. Bump per deploy.
    'version'       => env('BALLPICKER_APP_VERSION', 'v1'),

    // Marketing / legal website (privacy, terms). NOT the app frontend — see
    // frontend_url below for deep links back into the running app.
    'web_url'       => env('BALLSPOT_WEB_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Frontend app URL (password reset & other deep links)
    |--------------------------------------------------------------------------
    | Base URL of the running Expo/web app the user actually opens. In local
    | dev the Expo web server runs on :8081, so APP_URL (http://localhost, no
    | port) is the WRONG base for reset links — set FRONTEND_URL explicitly.
    | Production: FRONTEND_URL=https://your-production-domain.com
    |
    | password_reset_url optionally overrides the full reset screen URL. When
    | unset we append "/reset-password" to frontend_url. The token + email are
    | always added as query params so the app can complete the reset.
    */
    'frontend_url'       => env('FRONTEND_URL', env('BALLSPOT_WEB_URL', env('APP_URL', 'http://localhost'))),
    'password_reset_url' => env('PASSWORD_RESET_URL'),

    // User-facing brand name for emails/notifications (config('app.name') stays
    // the framework name). Kept configurable as the product is renamed.
    'app_name'      => env('BALLSPOT_APP_NAME', 'BallPicker'),

    /*
    |--------------------------------------------------------------------------
    | Legal / consent
    |--------------------------------------------------------------------------
    | minimum_age is the age users attest to at registration and is rendered in
    | the Terms and Privacy pages. 16 is the GDPR Art. 8 default; several member
    | states set it as low as 13. Lower it only with legal advice for the
    | markets you actually ship to.
    |
    | terms_version is stamped on each account at registration so a later policy
    | change can be re-consented rather than silently applied.
    */
    'legal' => [
        'minimum_age'   => (int) env('BALLSPOT_MINIMUM_AGE', 16),
        'terms_version' => env('BALLSPOT_TERMS_VERSION', '2026-08'),
    ],

    // Closed-beta gate: when set, registration requires a matching beta_code
    // (case-insensitive). Empty/null = registration open. Share one code with
    // testers; rotate by changing the env value.
    'beta_code'     => env('BALLPICKER_BETA_CODE') ?: null,

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
    | Leaderboard
    |--------------------------------------------------------------------------
    | Visible label for the recurring daily-challenge leaderboard period. The
    | aggregation window is currently WEEKLY (Mon–Sun, see
    | DailyChallengeController::weeklyLeaderboard). Changing this label alone is a
    | UI-only rename; a real "Monthly" competition ALSO requires changing that
    | window (and the weekly rank query) — deliberately out of scope for now.
    | The label is echoed in the weekly leaderboard API response as `period_label`
    | so the mobile app never hardcodes "Weekly".
    */
    'leaderboard' => [
        'period_label' => env('BALLPICKER_LEADERBOARD_PERIOD_LABEL', 'Weekly'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Competition period (daily-challenge leaderboard window)
    |--------------------------------------------------------------------------
    | Drives the recurring leaderboard aggregation window AND the top-10% badge.
    | 'monthly' = current calendar month (default); 'weekly' = Mon–Sun. The
    | label is derived from the period unless overridden. Timezone controls the
    | boundary (defaults to the app timezone). Changing this changes what the
    | leaderboard and the weekly_top_10 badge aggregate — deliberately one knob.
    */
    'competition' => [
        'period'   => env('BALLPICKER_COMPETITION_PERIOD', 'monthly'), // weekly | monthly
        'label'    => env('BALLPICKER_COMPETITION_LABEL'),             // optional override
        'timezone' => env('BALLPICKER_COMPETITION_TIMEZONE'),          // null = config('app.timezone')
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
        // Flat bonus for completing a challenge pack (on top of per-guess XP).
        'pack_completion' => (int) env('BALLPICKER_PACK_COMPLETION_XP', 250),
        // Monthly/weekly competition close bonuses (placement => XP). Awarded
        // once per closed period by ballspot:close-competition — virtual only.
        'competition_finish' => [
            1 => 2000,
            2 => 1000,
            3 => 500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tournament integrity
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Notifications & reminders
    |--------------------------------------------------------------------------
    | Opt-in local reminders (scheduled on-device) plus admin announcements
    | delivered via Expo's stateless push HTTP API. default_reminder_time is the
    | seed value for a new user's settings row; each user can override it. No
    | gambling / prizes / money — purely engagement reminders.
    */
    'notifications' => [
        'default_reminder_time' => env('BALLPICKER_DEFAULT_REMINDER_TIME', '19:00'),
        'expo_push_url'         => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        // When false, admin announcements are saved but not delivered (marked
        // "not sent") — we never fake a send when push is intentionally off.
        'push_enabled'          => (bool) env('BALLPICKER_PUSH_ENABLED', true),
        // Server-sent Daily Challenge reminder pushes. Deliberately OFF by
        // default: enable only once the app build that suppresses the local
        // daily reminder is live, or users on old builds get notified twice.
        'daily_reminder_push_enabled' => (bool) env('BALLPICKER_DAILY_REMINDER_PUSH_ENABLED', false),
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
        // Minimum distinct players a completed tournament must have before any
        // placement XP or podium badges are awarded. Blocks the solo-tournament
        // farm (create → start → one guess → "win" 1000 XP, on a loop). A finish
        // row is still recorded below this threshold; only the rewards are gated.
        // (v1.8.8: moved here from a duplicate 'tournaments' key that PHP
        // silently discarded — do not split this array again.)
        'min_players_for_rewards' => (int) env('BALLSPOT_TOURNAMENT_MIN_PLAYERS_FOR_REWARDS', 2),

        // v1.8.8: one hosted lobby/active tournament at a time.
        'max_created_per_user'         => (int) env('BALLSPOT_MAX_CREATED_TOURNAMENTS', 1),

        // v1.8.8: a user can be in at most 2 lobby/active tournaments (hosting counts).
        'max_active_memberships_per_user' => (int) env('BALLSPOT_MAX_ACTIVE_TOURNAMENT_MEMBERSHIPS', 2),

        'max_players_per_tournament'   => (int) env('BALLSPOT_MAX_PLAYERS_PER_TOURNAMENT', 8),

        // Allowed tournament lengths (days). One photo per day, so this is also
        // the number of unique photos a tournament needs. "1 month" = 30 days.
        // Old tournaments with other durations keep working; only creation is
        // restricted.
        'allowed_duration_days' => [7, 14, 30],

        // DEFAULT soft cooldown for reusing a photo in a new tournament: photos
        // any member guessed within this many days are avoided when enough
        // fresh eligible photos exist. Admin can override it at /admin/settings
        // (gameplay_settings.tournament_challenge_cooldown_days). 0 = disabled.
        // Daily-used photos stay hard-excluded regardless of this value.
        'challenge_cooldown_days' => (int) env('BALLSPOT_TOURNAMENT_CHALLENGE_COOLDOWN_DAYS', 90),

        // Premium placeholders (not enforced yet — no billing exists).
        'premium_max_created_per_user'       => (int) env('BALLSPOT_PREMIUM_MAX_CREATED_TOURNAMENTS', 20),
        'premium_max_players_per_tournament' => (int) env('BALLSPOT_PREMIUM_MAX_PLAYERS_PER_TOURNAMENT', 32),
    ],
];
