@extends('admin.layout')

@section('title', 'Settings')

@php
    $flagBadge = function (string $state): string {
        return match ($state) {
            'enabled'        => '<span class="badge badge-ok">enabled</span>',
            'launch_control' => '<span class="badge badge-soft-warn" title="Deliberately off for the launch">launch control · off</span>',
            'warning'        => '<span class="badge bg-danger">on — check before launch</span>',
            default          => '<span class="badge badge-soft">disabled</span>',
        };
    };
    $onOff = fn (bool $v, string $on = 'enabled', string $off = 'disabled') => $v
        ? '<span class="badge badge-ok">' . e($on) . '</span>'
        : '<span class="badge badge-soft">' . e($off) . '</span>';
    $g = $s['gameplay']; $c = $s['competition']; $gr = $s['growth']; $sec = $s['security'];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Settings</h1>
        <div class="text-muted small">One editable gameplay setting; everything else is a read-only view of the server configuration (<code>.env</code>). No secrets are shown here.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        @foreach($s['links'] as $link)
            <a href="{{ $link['url'] }}" class="btn btn-sm btn-outline-secondary">{{ $link['label'] }} ›</a>
        @endforeach
    </div>
</div>

@if($s['config_cached'])
<div class="alert alert-secondary py-2 small mb-3">
    Config cache is <strong>active</strong>: a changed <code>.env</code> only takes effect after <code>php artisan config:cache</code> (production deploy step) or <code>php artisan config:clear</code> (local).
</div>
@endif

<div class="row g-4">

    {{-- 1. Gameplay (editable) --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="gameplay">
            <div class="card-header d-flex align-items-center gap-2"><strong>Gameplay settings</strong> <span class="badge badge-active">editable</span></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.settings.update') }}">
                    @csrf
                    @method('PUT')
                    <label for="tournament_challenge_cooldown_days" class="form-label fw-semibold mb-1">Tournament challenge cooldown</label>
                    <div class="text-muted small mb-2">BallPicker prefers tournament photos that players have not seen within this many days. If not enough fresh photos exist, older eligible tournament photos may still be reused. Daily-used photos are never used. Applies to tournament selection only.</div>
                    <div class="d-flex align-items-start gap-2 flex-wrap">
                        <div class="input-group input-group-sm" style="max-width: 200px;">
                            <input type="number" class="form-control @error('tournament_challenge_cooldown_days') is-invalid @enderror"
                                   id="tournament_challenge_cooldown_days" name="tournament_challenge_cooldown_days"
                                   min="{{ $cooldownMin }}" max="{{ $cooldownMax }}" step="1" inputmode="numeric"
                                   value="{{ old('tournament_challenge_cooldown_days', $cooldownDays) }}" required
                                   aria-describedby="cooldown-help">
                            <span class="input-group-text">days</span>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                    @error('tournament_challenge_cooldown_days')
                        <div class="text-danger small mt-1" role="alert">{{ $message }}</div>
                    @enderror
                    <div id="cooldown-help" class="form-text">
                        Whole number between {{ $cooldownMin }} and {{ $cooldownMax }}. <strong>0 disables the cooldown</strong> (any eligible photo may be reused at once).
                        Default {{ $cooldownDefault }} — currently
                        @if($g['cooldown_overridden'])
                            <span class="badge badge-soft-ok">{{ $g['cooldown_days'] }} days (admin override)</span>
                        @else
                            <span class="badge badge-soft">{{ $g['cooldown_days'] }} days (config default)</span>
                        @endif
                    </div>
                </form>
            </div>
            <div class="card-footer small text-muted">Saved in <code>gameplay_settings</code>; takes effect for the next tournament that is created. Nothing else on this page writes.</div>
        </div>
    </div>

    {{-- 2. Competition (read-only) --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="competition">
            <div class="card-header d-flex align-items-center gap-2"><strong>Competition settings</strong> <span class="badge badge-soft">read-only · .env</span> <a href="/admin/competition" class="small ms-auto">Competition dashboard ›</a></div>
            <table class="table table-sm mb-0">
                <tr><th><code>BALLPICKER_COMPETITION_PERIOD</code></th><td>
                    <code>{{ $c['period_value'] ?? '(unset)' }}</code>
                    @if($c['period_valid'])<span class="text-muted small">→ {{ $c['period_effective'] }}</span>@else<span class="badge bg-warning text-dark">invalid — using {{ $c['period_effective'] }}</span>@endif
                </td></tr>
                <tr><th><code>BALLPICKER_COMPETITION_LABEL</code></th><td><code>{{ $c['label_value'] ?? '(unset)' }}</code> <span class="text-muted small">→ shown as "{{ $c['label_effective'] }}"</span></td></tr>
                <tr><th>Current window</th><td>{{ $c['window_start'] }} → {{ $c['window_end'] }} <span class="text-muted small">({{ $c['timezone'] }})</span></td></tr>
                <tr><th>Closing</th><td><span class="badge badge-soft">CLI only</span> <span class="text-muted small"><code>php artisan ballspot:close-competition --dry-run</code>, then without <code>--dry-run</code></span></td></tr>
            </table>
            <div class="card-footer small text-muted">Changing the period changes what the leaderboard and the top-finishers badge aggregate. Set it in <code>.env</code>, then refresh the config cache.</div>
        </div>
    </div>

    {{-- 3. Launch / feature flags (read-only) --}}
    <div class="col-12">
        <div class="card shadow-sm" data-section="flags">
            <div class="card-header d-flex align-items-center gap-2"><strong>Launch / feature flags</strong> <span class="badge badge-soft">read-only · .env</span> <a href="/admin/diagnostics" class="small ms-auto">Diagnostics ›</a></div>
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Flag</th><th>Env</th><th>State</th><th>Meaning now</th></tr></thead>
                <tbody>
                @foreach($s['flags'] as $flag)
                    <tr>
                        <td>{{ $flag['label'] }}</td>
                        <td><code>{{ $flag['env'] }}</code></td>
                        <td>{!! $flagBadge($flag['state']) !!}</td>
                        <td class="text-muted small">{{ $flag['note'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="card-footer small text-muted">
                <span class="badge badge-ok">enabled</span> on ·
                <span class="badge badge-soft">disabled</span> off ·
                <span class="badge badge-soft-warn">launch control · off</span> deliberately off for the launch ·
                <span class="badge bg-danger">on — check before launch</span> risky value.
                Flags are read from <code>.env</code> and are not editable here on purpose. Values such as the beta code are never shown.
            </div>
        </div>
    </div>

    {{-- 4. Growth / review (read-only) --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="growth">
            <div class="card-header d-flex align-items-center gap-2"><strong>Growth / review settings</strong> <span class="badge badge-soft">read-only</span> <a href="/admin/growth" class="small ms-auto">Growth stats ›</a></div>
            <table class="table table-sm mb-0">
                <tr><th>Share rewards</th><td>{!! $onOff(true, 'enabled') !!} <span class="text-muted small">in-app sharing only, server-side limits</span></td></tr>
                <tr><th>First share</th><td>+{{ $gr['share_rewards']['first_share_xp'] }} XP <span class="text-muted small">once per account · First Share trophy</span></td></tr>
                <tr><th>Daily result share</th><td>+{{ $gr['share_rewards']['daily_share_xp'] }} XP <span class="text-muted small">once per calendar day</span></td></tr>
                <tr><th>High-score share bonus</th><td>+{{ $gr['share_rewards']['daily_high_score_xp'] }} XP <span class="text-muted small">score ≥ {{ $gr['share_rewards']['high_score_threshold'] }}, once per calendar day · Daily Bragger trophy</span></td></tr>
                <tr><th>Social Striker</th><td>{{ $gr['share_rewards']['social_striker_shares'] }} shares</td></tr>
                <tr><th>Automatic review prompt</th><td>{!! $onOff(true, 'enabled (app policy)') !!} <span class="text-muted small">native only, never on first launch, after a daily score ≥ {{ $gr['review_prompt']['score_threshold'] }}, the {{ implode(' or ', array_map(fn ($m) => $m . ($m === 3 ? 'rd' : 'th'), $gr['review_prompt']['milestones'])) }} completed challenge, or a completed pack</span></td></tr>
                <tr><th>Review cooldown</th><td>{{ $gr['review_prompt']['cooldown_days'] }} days</td></tr>
                <tr><th>Review max attempts</th><td>{{ $gr['review_prompt']['max_attempts'] }} per install</td></tr>
                <tr><th>Review rewards</th><td><span class="badge badge-soft">not allowed</span> <span class="text-muted small">rating or reviewing never grants anything</span></td></tr>
            </table>
            <div class="card-footer small text-muted"><strong>Reviews are never rewarded with XP/trophies.</strong> Share amounts come from <code>BALLPICKER_SHARE_*</code> in <code>.env</code>; the review prompt policy is compiled into the app build and is not configurable server-side.</div>
        </div>
    </div>

    {{-- 5. Security / anti-abuse (read-only) --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="security">
            <div class="card-header d-flex align-items-center gap-2"><strong>Security / anti-abuse settings</strong> <span class="badge badge-soft">read-only · .env</span></div>
            <table class="table table-sm mb-0">
                <tr><th>CAPTCHA / Turnstile</th><td>
                    {!! $onOff($sec['captcha_enabled']) !!}
                    <span class="text-muted small">provider <code>{{ $sec['captcha_provider'] }}</code> · site key {{ $sec['captcha_site_key_set'] ? 'set' : 'not set' }} · secret {{ $sec['captcha_secret_set'] ? 'set' : 'not set' }} (values never shown)</span>
                    @if($sec['captcha_enabled'] && !$sec['captcha_secret_set'])<span class="badge bg-danger">enabled without a secret — registration will fail</span>@endif
                </td></tr>
                <tr><th>Honeypot field</th><td>{!! $onOff($sec['honeypot_enabled']) !!} <span class="text-muted small">hidden register field; a filled value blocks the IP for an hour</span></td></tr>
                <tr><th>Registration</th><td>
                    <div>{{ $sec['register']['request_throttle'] }} <span class="text-muted small">requests</span></div>
                    <div>{{ $sec['register']['accounts_per_ip'] }} <span class="text-muted small">accounts created per IP</span></div>
                    <div>{{ $sec['register']['attempts_per_email'] }} <span class="text-muted small">attempts per email · {{ $sec['register']['attempts_per_username'] }} per username</span></div>
                </td></tr>
                <tr><th>Verification emails</th><td>{{ $sec['verification_emails'] }}</td></tr>
                <tr><th>Login code (2FA) emails</th><td>{{ $sec['login_2fa_emails'] }}</td></tr>
                <tr><th>Forgot password</th><td>{{ $sec['password_reset'] }}</td></tr>
                <tr><th>Mail transport</th><td><code>{{ $sec['mailer'] }}</code> <span class="text-muted small">credentials are never shown here</span></td></tr>
            </table>
            <div class="card-footer small text-muted">Limits come from <code>BALLPICKER_REGISTER_*</code>, <code>BALLPICKER_VERIFICATION_EMAILS_*</code>, <code>BALLPICKER_LOGIN_2FA_EMAILS_*</code> and <code>BALLPICKER_PASSWORD_RESET_*</code>; the CAPTCHA playbook is in docs/security-hardening.md. The abuse counters live on <a href="/admin/diagnostics">Diagnostics</a>.</div>
        </div>
    </div>

</div>
@endsection
