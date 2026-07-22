<?php

return [
    'support_email' => env('BALLSPOT_SUPPORT_EMAIL', 'support@ballspot.app'),
    'web_url'       => env('BALLSPOT_WEB_URL', env('APP_URL', 'http://localhost')),

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
