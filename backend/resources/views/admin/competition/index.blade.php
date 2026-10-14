@extends('admin.layout')

@section('title', 'Competition')

@php
    $fmt   = fn ($dt) => $dt ? $dt->format('Y-m-d H:i') : '—';
    $na    = '<span class="text-muted">N/A</span>';
    $num   = fn ($v) => $v === null ? $na : e($v);
    $cur   = $d['current'];
    $prev  = $d['previous'];
    $acc   = $d['accounts'];
    $cfg   = $d['config'];
    $cmd   = $d['commands'];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Competition</h1>
        <div class="text-muted small">Read-only dashboard generated at {{ $fmt($d['generated_at']) }} ({{ $d['timezone'] }}). Numbers come from the same standings logic as the app's leaderboard. Reload for fresh numbers.</div>
    </div>
    <a href="{{ route('admin.competition.index') }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
</div>

{{-- Headline tiles --}}
<div class="row g-2 mb-4" data-section="headline">
    @foreach([
        'Players this period'   => $cur['players'],
        'Guesses this period'   => $cur['guesses'],
        'Average score'         => $cur['average_score'] ?? null,
        'Days remaining'        => $cur['ends_today'] ? 'ends today' : $cur['days_remaining'],
        'Active accounts'       => $acc['active'],
        'New accounts (7 days)' => $acc['new_7d'],
    ] as $label => $value)
        <div class="col-6 col-md-2">
            <div class="border rounded p-2 bg-white h-100">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fs-5 fw-semibold">{!! $num($value) !!}</div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-4">

    {{-- 1. Current period --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="current">
            <div class="card-header d-flex align-items-center gap-2">
                <strong>Current competition period</strong>
                <span class="badge badge-active">{{ ucfirst($cur['period_type']) }}</span>
                <span class="badge bg-success">live</span>
            </div>
            <table class="table table-sm mb-0">
                <tr><th>Visible label</th><td>{{ $cur['period_label'] }}</td></tr>
                <tr><th>Window</th><td>{{ $cur['period_start'] }} → {{ $cur['period_end'] }}</td></tr>
                <tr><th>Days remaining</th><td>{{ $cur['ends_today'] ? 'Ends today' : $cur['days_remaining'] . ' day(s)' }} <span class="text-muted small">(closes after {{ $cur['period_end'] }})</span></td></tr>
                <tr><th>Players</th><td>{{ $cur['players'] }} <span class="text-muted small">accounts with at least one daily guess in the window (deleted accounts excluded, as on the leaderboard)</span></td></tr>
                <tr><th>Daily guesses</th><td>{{ $cur['guesses'] }}</td></tr>
                <tr><th>Average score</th><td>{!! $num($cur['average_score']) !!} <span class="text-muted small">per guess</span></td></tr>
                <tr><th>Highest score</th><td>
                    {!! $num($cur['highest_guess']) !!} <span class="text-muted small">best single guess</span>
                    @if($cur['leader_total'] !== null) · leader total <strong>{{ $cur['leader_total'] }}</strong>@endif
                </td></tr>
                <tr><th>Top-finisher trophy eligible</th><td>
                    @if($cur['top_finisher_eligible'] > 0)
                        <span class="badge bg-light text-dark">{{ $cur['top_finisher_eligible'] }}</span> <span class="text-muted small">top 10% of {{ $cur['players'] }} players (monthly_top_10 at close)</span>
                    @else
                        <span class="badge bg-secondary">0</span> <span class="text-muted small">needs a field of {{ $cur['top_finisher_min_field'] }}+ players ({{ $cur['players'] }} now)</span>
                    @endif
                </td></tr>
                <tr><th>Last leaderboard recalculation</th><td>{!! $na !!} <span class="text-muted small">computed live on every request — nothing is cached</span></td></tr>
            </table>
        </div>
    </div>

    {{-- 2. Previous period --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="previous">
            <div class="card-header d-flex align-items-center gap-2">
                <strong>Previous competition period</strong>
                <span class="text-muted small">(close target)</span>
            </div>
            <table class="table table-sm mb-0">
                <tr><th>Period</th><td>{{ $prev['period_label'] }} <span class="text-muted small">{{ ucfirst($prev['period_type']) }}</span></td></tr>
                <tr><th>Window</th><td>{{ $prev['period_start'] }} → {{ $prev['period_end'] }}</td></tr>
                <tr><th>Close status</th><td>
                    @if ($prev['closed'])
                        <span class="badge badge-active">Closed — trophies awarded</span>
                    @elseif ($prev['players'] === 0)
                        <span class="badge bg-secondary">Nothing to close</span> <span class="text-muted small">no eligible players</span>
                    @else
                        <span class="badge bg-warning text-dark">Not closed yet</span>
                    @endif
                </td></tr>
                <tr><th>Closed at</th><td>{{ $fmt($prev['closed_at']) }}</td></tr>
                <tr><th>Players</th><td>{{ $prev['players'] }}</td></tr>
                <tr><th>Daily guesses</th><td>{{ $prev['guesses'] }}</td></tr>
                <tr><th>Average score</th><td>{!! $num($prev['average_score']) !!}</td></tr>
                <tr><th>Trophies awarded</th><td>
                    @if ($prev['closed'])
                        <strong>{{ $prev['trophies_awarded'] }}</strong> badge(s) · {{ $prev['podium_finishes'] }} podium finish(es) · {{ $prev['xp_awarded'] }} XP
                        @unless($prev['awards_badges'])<span class="text-muted small">(weekly closes store finishes only — no badges by design)</span>@endunless
                    @else
                        <span class="text-muted">— not closed —</span>
                    @endif
                </td></tr>
                <tr><th>Last closed period</th><td>
                    @if ($prev['last_closed'])
                        {{ $prev['last_closed']['label'] }} <span class="text-muted small">({{ $prev['last_closed']['total_players'] }} players · {{ $fmt($prev['last_closed']['closed_at']) }})</span>
                    @else
                        <span class="text-muted">— none yet —</span>
                    @endif
                </td></tr>
            </table>
        </div>
    </div>

    {{-- 3. Leaderboard preview --}}
    <div class="col-lg-7">
        <div class="card shadow-sm h-100" data-section="leaderboard">
            <div class="card-header"><strong>Leaderboard preview</strong> <span class="text-muted small">— live top {{ \App\Services\CompetitionAdminStatsService::LEADERBOARD_PREVIEW_ROWS }}, {{ $cur['period_label'] }} ({{ $cur['period_start'] }} → {{ $cur['period_end'] }})</span></div>
            <table class="table table-sm mb-0">
                <thead><tr><th>#</th><th>Player</th><th class="text-end">Points</th><th class="text-end">Dailies</th><th class="text-end">Avg</th><th></th></tr></thead>
                <tbody>
                @forelse($d['leaderboard'] as $row)
                    <tr>
                        <td>{{ $row['placement'] }}</td>
                        <td>{{ $row['username'] }}</td>
                        <td class="text-end fw-semibold">{{ $row['total_score'] }}</td>
                        <td class="text-end">{{ $row['challenges_played'] }}</td>
                        <td class="text-end">{{ $row['avg_score'] }}</td>
                        <td>@if($row['tied'])<span class="badge bg-light text-dark" title="Same total as a neighbour — ordered by earliest last guess, then user id">tie</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">No daily guesses in this period yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="card-footer small text-muted">Usernames only. Deleted (anonymized) accounts are excluded exactly as on the app leaderboard. Ties are broken by the earliest last qualifying guess, then user id (same rule as the close command).</div>
        </div>
    </div>

    {{-- 4. Accounts --}}
    <div class="col-lg-5">
        <div class="card shadow-sm h-100" data-section="accounts">
            <div class="card-header"><strong>Accounts</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Active accounts</th><td>{{ $acc['active'] }}</td></tr>
                <tr><th>Deleted/anonymized accounts</th><td>{{ $acc['deleted'] }}</td></tr>
                <tr><th>New registrations (7 days)</th><td>{{ $acc['new_7d'] }}</td></tr>
                <tr><th>New registrations (24h)</th><td>{{ $acc['new_24h'] }}</td></tr>
            </table>
            <div class="card-footer small text-muted">Active accounts: {{ $acc['active'] }} · Deleted/anonymized accounts: {{ $acc['deleted'] }}. Aggregate counts only — deleted accounts stay anonymous.</div>
        </div>
    </div>

    {{-- 5. Admin actions --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100 border-warning" data-section="actions">
            <div class="card-header"><strong>Admin actions</strong> <span class="badge bg-warning text-dark">CLI only</span></div>
            <div class="card-body">
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Closing a period is deliberate and final for its standings.</strong> It stores the top-3 finishes and awards <strong>virtual XP and trophies only</strong> — no prizes, no money. There is no web button on purpose: preview with a dry run first, then close from the server.
                </div>
                <div class="small text-muted mb-1">Target: <strong>{{ $cmd['target'] }}</strong> (the most recently completed period). Scheduled automatically {{ $cmd['scheduled'] }}; run by hand only if that did not happen.</div>
                <pre class="mb-2 small bg-light p-2 rounded">cd /var/www/ballpicker/backend
# 1. Preview — writes nothing
{{ $cmd['dry_run'] }}
# 2. Close — stores finishes, awards XP + trophies (idempotent)
{{ $cmd['close'] }}</pre>
                <div class="small text-muted">Options: <code>--period=YYYY-MM</code> (or <code>YYYY-WW</code> with <code>--type=weekly</code>) to target another period, <code>--announce</code> to save a draft announcement (never auto-sent). <code>--force</code> closes an open period — do not use it during the live competition.</div>
            </div>
        </div>
    </div>

    {{-- 6. Configuration --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="config">
            <div class="card-header"><strong>Configuration</strong></div>
            <table class="table table-sm mb-0">
                <tr><th><code>{{ $cfg['period_env'] }}</code></th><td>
                    <code>{{ $cfg['period_value'] ?? '(unset)' }}</code>
                    @if(!$cfg['period_valid'])<span class="badge bg-warning text-dark">invalid — using {{ $cfg['period_effective'] }}</span>@else<span class="text-muted small">→ {{ $cfg['period_effective'] }}</span>@endif
                </td></tr>
                <tr><th><code>{{ $cfg['label_env'] }}</code></th><td><code>{{ $cfg['label_value'] ?? '(unset)' }}</code> <span class="text-muted small">→ shown as "{{ $cfg['label_effective'] }}"</span></td></tr>
                <tr><th><code>{{ $cfg['timezone_env'] }}</code></th><td><code>{{ $cfg['timezone'] }}</code> <span class="text-muted small">period boundaries</span></td></tr>
                <tr><th>Config cache</th><td>{!! $cfg['config_cached'] ? '<span class="badge bg-info text-dark">cached</span>' : '<span class="badge bg-secondary">not cached</span>' !!}</td></tr>
            </table>
            <div class="card-footer small text-muted">
                Set <code>BALLPICKER_COMPETITION_PERIOD=weekly|monthly</code> (and optionally <code>BALLPICKER_COMPETITION_LABEL</code>) in the backend <code>.env</code>. A changed <code>.env</code> is <strong>not</strong> picked up while the config cache is active: run <code>php artisan config:clear</code> (local) or the production deploy step <code>php artisan config:cache</code>. The leaderboard window and the top-finishers badge follow this setting.
            </div>
        </div>
    </div>

    {{-- 7. Growth & sharing --}}
    @if($d['growth'] !== null)
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="growth">
            <div class="card-header d-flex align-items-center gap-2"><strong>Growth &amp; sharing summary</strong> <a href="{{ route('admin.growth.index') }}" class="small ms-auto">full page ›</a></div>
            @if(!$d['growth']['has_events'])
                <div class="card-body text-muted small">Growth events are not tracked yet.</div>
            @else
                <table class="table table-sm mb-0">
                    <tr><th>Total app shares</th><td>{{ $d['growth']['totals']['app_shares'] }}</td></tr>
                    <tr><th>Total daily result shares</th><td>{{ $d['growth']['totals']['daily_result_shares'] }}</td></tr>
                    <tr><th>Total high-score result shares</th><td>{{ $d['growth']['totals']['high_score_result_shares'] }}</td></tr>
                    <tr><th>Total share rewards claimed</th><td>{{ $d['growth']['totals']['share_rewards_claimed'] }}</td></tr>
                    <tr><th>Total rate button taps</th><td>{{ $d['growth']['totals']['rate_button_taps'] }}</td></tr>
                    <tr><th>Total review prompt attempts</th><td>{{ $d['growth']['totals']['review_prompt_attempts'] }}</td></tr>
                    <tr><th>Total native review requests</th><td>{{ $d['growth']['totals']['native_review_requests'] }}</td></tr>
                    <tr><th>Last 7 days shares</th><td>{{ $d['growth']['last_7_days']['shares'] }}</td></tr>
                    <tr><th>Last 7 days review attempts</th><td>{{ $d['growth']['last_7_days']['review_attempts'] }}</td></tr>
                </table>
            @endif
            <div class="card-footer small text-muted">Review attempts are not confirmed App Store / Google Play reviews. Native review APIs do not reliably confirm whether a review was submitted.</div>
        </div>
    </div>
    @endif

    {{-- 8. Security / auth abuse --}}
    @php $ab = $d['abuse']; $abuseCounts = collect($ab['rows'])->keyBy('event'); @endphp
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="abuse">
            <div class="card-header d-flex align-items-center gap-2"><strong>Security / auth abuse</strong> <span class="text-muted small">({{ $ab['window'] }})</span> <a href="{{ route('admin.diagnostics.index') }}" class="small ms-auto">diagnostics ›</a></div>
            <table class="table table-sm mb-0">
                <tr><th>Registrations</th><td>{{ $ab['registrations_24h'] }}</td></tr>
                <tr><th>Registration rate-limited</th><td>{{ $abuseCounts['register.rate_limited']['count_24h'] ?? 0 }}</td></tr>
                <tr><th>Verification emails sent</th><td>{{ $ab['verification_emails_sent_24h'] }}</td></tr>
                <tr><th>Verification resend rate-limited</th><td>{{ $abuseCounts['verification.resend_rate_limited']['count_24h'] ?? 0 }}</td></tr>
                <tr><th>Password reset requests</th><td>{{ $ab['password_reset_requests_24h'] }}</td></tr>
                <tr><th>Password reset rate-limited</th><td>{{ $abuseCounts['password_reset.rate_limited']['count_24h'] ?? 0 }}</td></tr>
                <tr><th>Login 2FA rate-limited</th><td>{{ $abuseCounts['login_2fa.rate_limited']['count_24h'] ?? 0 }}</td></tr>
                <tr><th>Honeypot triggers</th><td>{{ $abuseCounts['honeypot.triggered']['count_24h'] ?? 0 }}</td></tr>
                <tr><th>Mail provider failures</th><td>{{ $ab['mail_failures_24h'] }} <span class="text-muted small">verification / login-code / reset emails that could not be handed to the transport</span></td></tr>
                <tr><th>CAPTCHA / Turnstile</th><td>{!! $ab['captcha_enabled'] ? '<span class="badge bg-info text-dark">enabled</span>' : '<span class="badge bg-secondary">disabled</span>' !!}</td></tr>
            </table>
            <div class="card-footer small text-muted">
                @unless($ab['events_log_found'])No events log for the last 24h was found — counts are 0 until the app writes one. @endunless
                Event names and counts from the events log only; no address, code, token or password is ever shown.
            </div>
        </div>
    </div>

</div>
@endsection
