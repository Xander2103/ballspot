<?php

namespace App\Services;

use App\Models\GameplaySetting;
use Carbon\Carbon;

/**
 * Read-only configuration overview for /admin/settings (v1.9.14).
 *
 * Everything here is a flag, a number or a label. It never returns a secret
 * (APP_KEY, DB/mail passwords, Brevo credentials, the CAPTCHA secret or site
 * key, the beta code) and never writes. The only editable setting on the
 * page — the tournament challenge cooldown — stays in SettingsController.
 */
class AdminSettingsOverviewService
{
    /**
     * The automatic review prompt is a device-side policy compiled into the
     * app (mobile/src/utils/reviewPrompt.ts); the backend neither stores nor
     * enforces it. Shown for reference only — keep in sync with that file.
     */
    public const APP_REVIEW_PROMPT = [
        'cooldown_days'   => 30,
        'max_attempts'    => 3,
        'score_threshold' => 80,
        'milestones'      => [3, 5],
    ];

    public function __construct(private CompetitionPeriodService $period) {}

    public function overview(?Carbon $now = null): array
    {
        $now = $now ?? now();

        return [
            'gameplay'    => $this->gameplay(),
            'competition' => $this->competition($now),
            'flags'       => $this->launchFlags(),
            'growth'      => $this->growth(),
            'security'    => $this->security(),
            'links'       => [
                ['label' => 'Competition dashboard', 'url' => '/admin/competition'],
                ['label' => 'Diagnostics',           'url' => '/admin/diagnostics'],
                ['label' => 'Growth stats',          'url' => '/admin/growth'],
            ],
            'config_cached' => app()->configurationIsCached(),
        ];
    }

    private function gameplay(): array
    {
        return [
            'cooldown_days'    => GameplaySetting::tournamentChallengeCooldownDays(),
            'cooldown_default' => (int) config('ballspot.tournaments.challenge_cooldown_days', 90),
            'cooldown_min'     => GameplaySetting::COOLDOWN_MIN_DAYS,
            'cooldown_max'     => GameplaySetting::COOLDOWN_MAX_DAYS,
            'cooldown_overridden' => GameplaySetting::query()->find(GameplaySetting::TOURNAMENT_CHALLENGE_COOLDOWN_DAYS) !== null,
        ];
    }

    private function competition(Carbon $now): array
    {
        $raw = config('ballspot.competition.period');
        $current = $this->period->toArray($now);

        return [
            'period_value'     => is_string($raw) ? $raw : null,
            'period_valid'     => in_array($raw, [CompetitionPeriodService::WEEKLY, CompetitionPeriodService::MONTHLY], true),
            'period_effective' => $this->period->type(),
            'label_value'      => config('ballspot.competition.label') ?: null,
            'label_effective'  => $this->period->label(),
            'timezone'         => $this->period->timezone(),
            'window_start'     => $current['period_start'],
            'window_end'       => $current['period_end'],
        ];
    }

    /**
     * Launch / feature flags as badges. `state` is one of enabled | disabled |
     * launch_control (deliberately off for launch) | warning (risky value).
     */
    private function launchFlags(): array
    {
        $autoSchedule = (bool) config('ballspot.daily.auto_schedule', true);
        $autoActivate = (bool) config('ballspot.daily.auto_activate', false);
        $force2fa     = (bool) config('ballspot.auth.force_login_2fa', false);
        $optIn2fa     = (bool) config('ballspot.auth.login_2fa_opt_in', false);
        $betaGate     = (bool) config('ballspot.beta_code');
        $push         = (bool) config('ballspot.notifications.push_enabled', true);
        $reminderPush = (bool) config('ballspot.notifications.daily_reminder_push_enabled', false);
        $verification = (bool) config('ballspot.auth.require_email_verification', true);

        return [
            ['env' => 'BALLPICKER_AUTO_SCHEDULE_DAILIES', 'label' => 'Auto-schedule dailies', 'value' => $autoSchedule,
             'state' => $autoSchedule ? 'enabled' : 'launch_control', 'note' => $autoSchedule ? 'the 00:05 cron fills the next 14 days' : 'launch control: dailies are only created by hand'],
            ['env' => 'BALLPICKER_AUTO_ACTIVATE_DAILIES', 'label' => "Auto-activate today's daily", 'value' => $autoActivate,
             'state' => $autoActivate ? 'enabled' : 'launch_control', 'note' => $autoActivate ? "the 00:10 cron activates today's row" : "today's daily must be activated in Admin → Daily (production should set true)"],
            ['env' => 'BALLPICKER_REQUIRE_EMAIL_VERIFICATION', 'label' => 'Email verification at registration', 'value' => $verification,
             'state' => $verification ? 'enabled' : 'warning', 'note' => $verification ? 'new accounts verify a 6-digit code' : 'OFF: new accounts are verified at once — emergency switch only'],
            ['env' => 'BALLPICKER_FORCE_LOGIN_2FA', 'label' => 'Force login 2FA for everyone', 'value' => $force2fa,
             'state' => $force2fa ? 'warning' : 'disabled', 'note' => $force2fa ? 'every login emails a code — not intended for launch' : 'off: email + password logs in'],
            ['env' => 'BALLPICKER_LOGIN_2FA_OPT_IN', 'label' => 'Login 2FA opt-in (Profile toggle)', 'value' => $optIn2fa,
             'state' => $optIn2fa ? 'enabled' : 'launch_control', 'note' => $optIn2fa ? 'users may switch the login code on' : 'launch control: toggle hidden, API refuses new opt-ins'],
            ['env' => 'BALLPICKER_BETA_CODE', 'label' => 'Beta gate', 'value' => $betaGate,
             'state' => $betaGate ? 'warning' : 'disabled', 'note' => $betaGate ? 'registration requires a beta code (value never shown) — public launch needs it EMPTY' : 'off: registration is open'],
            ['env' => 'BALLPICKER_PUSH_ENABLED', 'label' => 'Push notifications', 'value' => $push,
             'state' => $push ? 'enabled' : 'disabled', 'note' => $push ? 'admin announcements are delivered' : 'announcements are saved but not sent'],
            ['env' => 'BALLPICKER_DAILY_REMINDER_PUSH_ENABLED', 'label' => 'Daily reminder push (server-sent)', 'value' => $reminderPush,
             'state' => $reminderPush ? 'enabled' : 'launch_control', 'note' => $reminderPush ? 'server reminders are sent' : 'off until the app build that suppresses local reminders is live'],
        ];
    }

    private function growth(): array
    {
        return [
            'share_rewards' => [
                'first_share_xp'        => (int) config('ballspot.share.first_share_xp', ShareRewardService::DEFAULT_FIRST_SHARE_XP),
                'daily_share_xp'        => (int) config('ballspot.share.daily_share_xp', ShareRewardService::DEFAULT_DAILY_SHARE_XP),
                'daily_high_score_xp'   => (int) config('ballspot.share.daily_high_score_xp', ShareRewardService::DEFAULT_DAILY_HIGH_SCORE_XP),
                'high_score_threshold'  => (int) config('ballspot.share.high_score_threshold', 80),
                'social_striker_shares' => (int) config('ballspot.share.social_striker_shares', 5),
            ],
            'review_prompt'   => self::APP_REVIEW_PROMPT,
            'review_rewards'  => false, // never — store policy, enforced server-side (POST /api/events awards nothing)
        ];
    }

    private function security(): array
    {
        return [
            'captcha_enabled'  => (bool) config('ballspot.captcha.enabled', false),
            'captcha_provider' => (string) config('ballspot.captcha.provider', 'turnstile'),
            // Presence only — the site key is public by design but still a key; the secret never leaves the server.
            'captcha_site_key_set' => trim((string) config('ballspot.captcha.site_key', '')) !== '',
            'captcha_secret_set'   => trim((string) config('ballspot.captcha.secret', '')) !== '',
            'honeypot_enabled'     => true, // RegisterRequest::HONEYPOT_FIELD is always active
            'register'  => [
                'request_throttle'   => '5/min + 20/hour per IP',
                'accounts_per_ip'    => sprintf('%d/hour, %d/day', (int) config('ballspot.abuse.register.per_ip_hour', 5), (int) config('ballspot.abuse.register.per_ip_day', 10)),
                'attempts_per_email' => sprintf('%d/hour', (int) config('ballspot.abuse.register.attempts_per_email_hour', 5)),
                'attempts_per_username' => sprintf('%d/hour', (int) config('ballspot.abuse.register.attempts_per_username_hour', 5)),
            ],
            'verification_emails' => sprintf('%d/hour, %d/day per address · 60 s resend cooldown', (int) config('ballspot.abuse.verification_email.per_email_hour', 5), (int) config('ballspot.abuse.verification_email.per_email_day', 10)),
            'login_2fa_emails'    => sprintf('%d/hour, %d/day per address · 60 s resend cooldown', (int) config('ballspot.abuse.login_2fa.per_email_hour', 3), (int) config('ballspot.abuse.login_2fa.per_email_day', 10)),
            'password_reset'      => sprintf('3/min per email+IP · %d/hour per address · %d/hour per IP', (int) config('ballspot.abuse.password_reset.per_email_hour', 3), (int) config('ballspot.abuse.password_reset.per_ip_hour', 10)),
            'mailer'              => (string) config('mail.default', 'log'),
        ];
    }
}
