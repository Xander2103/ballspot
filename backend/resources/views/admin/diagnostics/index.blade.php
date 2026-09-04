@extends('admin.layout')

@section('title', 'Diagnostics')

@php
    $yesNo = fn ($v) => $v
        ? '<span class="badge bg-success">yes</span>'
        : '<span class="badge bg-danger">no</span>';
    $fmt = fn ($dt) => $dt ? $dt->format('Y-m-d H:i:s') : '—';
    $warnCount = count($d['warnings']);
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Diagnostics</h1>
        <div class="text-muted small">Read-only snapshot generated at {{ $fmt($d['generated_at']) }} ({{ $d['app']['timezone'] }}). Reload for fresh numbers.</div>
    </div>
    <a href="{{ route('admin.diagnostics.index') }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
</div>

{{-- Warnings summary --}}
<div class="card shadow-sm mb-4" data-section="warnings">
    <div class="card-header d-flex align-items-center gap-2">
        <strong>Warnings</strong>
        @if($warnCount === 0)
            <span class="badge bg-success">all clear</span>
        @else
            <span class="badge bg-warning text-dark">{{ $warnCount }}</span>
        @endif
    </div>
    <div class="card-body py-2">
        @if($warnCount === 0)
            <div class="text-success small mb-0">No problems detected.</div>
        @else
            <ul class="list-unstyled mb-0">
                @foreach($d['warnings'] as $w)
                    <li class="py-1">
                        <span class="badge {{ $w['level'] === 'danger' ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $w['section'] }}</span>
                        <span class="ms-1">{{ $w['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

<div class="row g-4">

    {{-- 1. App status --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="app">
            <div class="card-header"><strong>App status</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>App</th><td>{{ $d['app']['name'] }} <span class="text-muted">{{ $d['app']['version'] }}</span></td></tr>
                <tr><th>APP_ENV</th><td><code>{{ $d['app']['env'] }}</code></td></tr>
                <tr><th>APP_DEBUG</th><td>{!! $d['app']['debug'] ? '<span class="badge bg-danger">true</span>' : '<span class="badge bg-success">false</span>' !!}</td></tr>
                <tr><th>APP_URL</th><td><code>{{ $d['app']['url'] }}</code></td></tr>
                <tr><th>Server time</th><td>{{ $fmt($d['app']['server_time']) }} {{ $d['app']['timezone'] }}</td></tr>
                <tr><th>PHP</th><td>{{ $d['app']['php_version'] }}</td></tr>
                <tr><th>Push enabled</th><td>{!! $yesNo($d['app']['push_enabled']) !!}</td></tr>
                <tr><th>Daily reminder push</th><td>{!! $yesNo($d['app']['daily_reminder_push_enabled']) !!} <span class="text-muted small">(server-sent reminders; OFF is expected until the app build that suppresses local reminders is live)</span></td></tr>
                <tr><th>Beta gate</th><td>{!! $d['app']['beta_gate_enabled'] ? '<span class="badge bg-info text-dark">on</span>' : '<span class="badge bg-secondary">off</span>' !!} <span class="text-muted small">(code is never shown here)</span></td></tr>
            </table>
        </div>
    </div>

    {{-- Log --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="log">
            <div class="card-header"><strong>Backend errors (log)</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Channel / level</th><td><code>{{ $d['log']['channel'] }}</code> / <code>{{ $d['log']['level'] }}</code></td></tr>
                <tr><th>Log file</th><td><code>{{ $d['log']['file'] }}</code> {!! $yesNo($d['log']['exists']) !!}</td></tr>
                <tr><th>Size / modified</th><td>{{ number_format($d['log']['size_bytes'] / 1024, 1) }} KB · {{ $fmt($d['log']['modified_at']) }}</td></tr>
                <tr><th>Errors (24h)</th><td><span class="badge {{ $d['log']['errors_24h'] > 0 ? 'bg-danger' : 'bg-success' }}">{{ $d['log']['errors_24h'] }}</span></td></tr>
                <tr><th>Warnings (24h)</th><td><span class="badge {{ $d['log']['warnings_24h'] > 0 ? 'bg-warning text-dark' : 'bg-success' }}">{{ $d['log']['warnings_24h'] }}</span></td></tr>
                <tr><th>Last error</th><td>{{ $fmt($d['log']['last_error_at']) }}
                    @if($d['log']['last_error_summary'])<div class="small text-muted">{{ $d['log']['last_error_summary'] }}</div>@endif
                </td></tr>
                <tr><th>Events file</th><td><code>{{ $d['log']['events_file'] }}</code> <span class="text-muted small">today: {!! $yesNo($d['log']['events_file_today']) !!}</span></td></tr>
                <tr><th>Failed flows (24h)</th><td>
                    @if(empty($d['log']['event_errors_24h']))
                        <span class="badge bg-success">none</span>
                    @else
                        @foreach($d['log']['event_errors_24h'] as $name => $count)
                            <span class="badge bg-danger me-1" title="events log">{{ $count }}× {{ $name }}</span>
                        @endforeach
                    @endif
                    <div class="small text-muted">account.delete_failed · password.reset_* · auth.verification_* · pack.completion_reward_failed</div>
                </td></tr>
            </table>
            <div class="card-footer small text-muted">Counts come from the tail of the log only. Full details: <code>tail -n 100 storage/logs/laravel.log</code>. Stack traces are never shown here.</div>
        </div>
    </div>

    {{-- 2. Queue --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="queue">
            <div class="card-header"><strong>Database / queue</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Queue connection</th><td><code>{{ $d['queue']['connection'] }}</code></td></tr>
                <tr><th>Queue tables present</th><td>{!! $yesNo($d['queue']['tables_present']) !!}</td></tr>
                <tr><th>Pending jobs</th><td>{{ $d['queue']['pending_jobs'] }}</td></tr>
                <tr><th>Jobs older than {{ \App\Services\DiagnosticsService::JOB_STALE_MINUTES }} min</th><td><span class="badge {{ $d['queue']['stale_jobs'] > 0 ? 'bg-warning text-dark' : 'bg-success' }}">{{ $d['queue']['stale_jobs'] }}</span></td></tr>
                <tr><th>Failed jobs</th><td><span class="badge {{ $d['queue']['failed_jobs'] > 0 ? 'bg-danger' : 'bg-success' }}">{{ $d['queue']['failed_jobs'] }}</span></td></tr>
                <tr><th>Latest failure</th><td>{{ $fmt($d['queue']['latest_failed_at']) }}</td></tr>
            </table>
            <div class="card-footer small text-muted">{{ $d['queue']['note'] }} Payloads are never shown here.</div>
        </div>
    </div>

    {{-- 3. Daily --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="daily">
            <div class="card-header"><strong>Scheduler / daily challenge</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Today ({{ $d['daily']['today'] }})</th><td>
                    @php $ts = $d['daily']['today_status']; @endphp
                    <span class="badge {{ $ts === 'active' ? 'bg-success' : 'bg-danger' }}">{{ $ts }}</span>
                    @if($d['daily']['today_challenge_id'])
                        <span class="text-muted small">#{{ $d['daily']['today_challenge_id'] }} {{ $d['daily']['today_challenge_title'] }}</span>
                    @endif
                </td></tr>
                <tr><th>Latest scheduled date</th><td>{{ $d['daily']['latest_scheduled_date'] ?? '—' }}</td></tr>
                <tr><th>Scheduled (today onward)</th><td>{{ $d['daily']['scheduled_count'] }}</td></tr>
                <tr><th>Active (future dates)</th><td>{{ $d['daily']['active_upcoming_count'] }}</td></tr>
                <tr><th>Daily pool available</th><td><span class="badge {{ $d['daily']['pool_available'] < $d['daily']['pool_low_threshold'] ? 'bg-warning text-dark' : 'bg-success' }}">{{ $d['daily']['pool_available'] }}</span> <span class="text-muted small">never-used, ready, daily/general pool (warn &lt; {{ $d['daily']['pool_low_threshold'] }})</span></td></tr>
                <tr><th>Cron</th><td><code>{{ $d['daily']['cron_command'] }}</code></td></tr>
            </table>
            <div class="card-footer small text-muted">The cron creates rows as <code>scheduled</code>; the app only serves <code>active</code>. Check <code>php artisan schedule:list</code> if nothing is being created.</div>
        </div>
    </div>

    {{-- 4. Content pool --}}
    <div class="col-12">
        <div class="card shadow-sm" data-section="content">
            <div class="card-header"><strong>Content pool</strong></div>
            <div class="card-body pb-0">
                <div class="row g-2 mb-3">
                    @foreach([
                        'Active + ready'        => $d['content']['active_ready'],
                        'Daily pool (ready)'    => $d['content']['daily_pool'],
                        'Daily available'       => $d['content']['daily_available'],
                        'Tournament eligible'   => $d['content']['tournament_eligible'],
                        'Pack-only (ready)'     => $d['content']['pack_only'],
                        'Used as daily'         => $d['content']['used_as_daily'],
                        'Draft'                 => $d['content']['draft'],
                        'Archived'              => $d['content']['archived'],
                    ] as $label => $value)
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 bg-white">
                                <div class="text-muted small">{{ $label }}</div>
                                <div class="fs-5 fw-semibold">{{ $value }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Sport</th><th>Status</th><th>Active ready</th><th>Daily eligible (unused)</th><th>Tournament eligible</th><th>Pack-only</th><th>Used as daily</th></tr></thead>
                <tbody>
                @forelse($d['content']['per_sport'] as $s)
                    @php $isActive = $s['status'] === 'active'; @endphp
                    <tr>
                        <td>{{ $s['name'] }} <span class="text-muted small">{{ $s['slug'] }}</span></td>
                        <td><span class="badge {{ $isActive ? 'bg-success' : 'bg-secondary' }}">{{ $s['status'] }}</span></td>
                        <td>{{ $s['active_ready'] }}</td>
                        <td><span class="badge {{ $isActive && $s['daily_eligible'] < $d['content']['thresholds']['daily'] ? 'bg-warning text-dark' : 'bg-light text-dark' }}">{{ $s['daily_eligible'] }}</span></td>
                        <td><span class="badge {{ $isActive && $s['tournament_eligible'] < $d['content']['thresholds']['tournament'] ? 'bg-warning text-dark' : 'bg-light text-dark' }}">{{ $s['tournament_eligible'] }}</span></td>
                        <td>{{ $s['pack_only'] }}</td>
                        <td>{{ $s['used_as_daily'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No sports found.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="card-footer small text-muted">Warnings apply to active sports only: tournament eligible &lt; {{ $d['content']['thresholds']['tournament'] }} or daily eligible &lt; {{ $d['content']['thresholds']['daily'] }}.</div>
        </div>
    </div>

    {{-- 5. Tournaments --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="tournaments">
            <div class="card-header"><strong>Tournaments</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Lobby</th><td>{{ $d['tournaments']['lobby'] }}</td></tr>
                <tr><th>Active</th><td>{{ $d['tournaments']['active'] }}</td></tr>
                <tr><th>Completed</th><td>{{ $d['tournaments']['completed'] }}</td></tr>
                <tr><th>Cancelled</th><td>{{ $d['tournaments']['cancelled'] }}</td></tr>
                <tr><th>Active but past end date</th><td><span class="badge {{ $d['tournaments']['expired_active'] > 0 ? 'bg-warning text-dark' : 'bg-success' }}">{{ $d['tournaments']['expired_active'] }}</span></td></tr>
            </table>
            <div class="card-footer small text-muted">{{ $d['tournaments']['completion_note'] }}</div>
        </div>
    </div>

    {{-- 6. Packs --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="packs">
            <div class="card-header"><strong>Packs</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Active public packs</th><td>{{ $d['packs']['active_public'] }} <span class="text-muted small">of {{ $d['packs']['total'] }} total</span></td></tr>
                <tr><th>Packs with 0 challenges</th><td><span class="badge {{ $d['packs']['zero_challenges'] > 0 ? 'bg-warning text-dark' : 'bg-success' }}">{{ $d['packs']['zero_challenges'] }}</span>
                    @if($d['packs']['zero_challenge_names'])<div class="small text-muted">{{ implode(', ', $d['packs']['zero_challenge_names']) }}</div>@endif
                </td></tr>
                <tr><th>With completion trophy</th><td>{{ $d['packs']['with_trophy'] }}</td></tr>
                <tr><th>Without completion trophy</th><td>{{ $d['packs']['without_trophy'] }}</td></tr>
            </table>
            <div class="card-footer small text-muted">Archived packs are excluded from the zero/trophy counts.</div>
        </div>
    </div>

    {{-- 7. Storage --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="storage">
            <div class="card-header"><strong>Storage / uploads</strong></div>
            <table class="table table-sm mb-0">
                <tr><th><code>{{ $d['storage']['root'] }}</code> exists</th><td>{!! $yesNo($d['storage']['root_exists']) !!}</td></tr>
                <tr><th>writable</th><td>{!! $yesNo($d['storage']['root_writable']) !!}</td></tr>
                <tr><th><code>{{ $d['storage']['link'] }}</code> link</th><td>{!! $yesNo($d['storage']['link_exists']) !!}</td></tr>
                @foreach($d['storage']['directories'] as $dir)
                    <tr><th>{{ $dir['label'] }}</th><td>{!! $yesNo($dir['exists']) !!} <span class="text-muted small">{{ $dir['files'] }} file(s) · <code>{{ $dir['path'] }}</code></span></td></tr>
                @endforeach
            </table>
        </div>
    </div>

    {{-- Activity --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="activity">
            <div class="card-header"><strong>Recent activity</strong> <span class="text-muted small">({{ $d['activity']['window'] }})</span></div>
            <table class="table table-sm mb-0">
                <tr><th>Registrations</th><td>{{ $d['activity']['registrations'] }}</td></tr>
                <tr><th>Account deletions</th><td>{{ $d['activity']['account_deletions'] }}</td></tr>
                <tr><th>Daily guesses</th><td>{{ $d['activity']['daily_guesses'] }}</td></tr>
                <tr><th>Tournament guesses</th><td>{{ $d['activity']['tournament_guesses'] }}</td></tr>
                <tr><th>Pack guesses</th><td>{{ $d['activity']['pack_guesses'] }}</td></tr>
                <tr><th>Tournaments created / completed</th><td>{{ $d['activity']['tournaments_created'] }} / {{ $d['activity']['tournaments_completed'] }}</td></tr>
                <tr><th>Pack completions</th><td>{{ $d['activity']['pack_completions'] }}</td></tr>
                <tr><th>Push devices seen / total</th><td>{{ $d['activity']['push_tokens_seen'] }} / {{ $d['activity']['push_tokens_total'] }}</td></tr>
                <tr><th>Users total</th><td>{{ $d['activity']['users_total'] }}</td></tr>
            </table>
            <div class="card-footer small text-muted">Counts only. Per-event detail lives in the events log.</div>
        </div>
    </div>

    {{-- 8. Manual operations --}}
    <div class="col-12">
        <div class="card shadow-sm" data-section="commands">
            <div class="card-header"><strong>Manual operations</strong> <span class="text-muted small">— copy/paste on the server; nothing here runs from the browser</span></div>
            <div class="card-body">
                <pre class="mb-2 small bg-light p-2 rounded">cd /var/www/ballpicker/backend</pre>
                <table class="table table-sm mb-0">
                    @foreach($d['commands'] as $c)
                        <tr><th class="text-nowrap">{{ $c['label'] }}</th><td><code>{{ $c['command'] }}</code></td></tr>
                    @endforeach
                </table>
                <div class="small text-muted mt-2">Full runbook: <code>docs/ops-runbook.md</code>.</div>
            </div>
        </div>
    </div>

</div>
@endsection
