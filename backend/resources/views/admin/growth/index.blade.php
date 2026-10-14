@extends('admin.layout')

@section('title', 'Growth')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">Growth — shares &amp; review attempts</h1>
        <div class="text-muted small">Read-only counters generated at {{ $g['generated_at']->format('Y-m-d H:i:s') }}. Reload for fresh numbers.</div>
    </div>
    <a href="{{ route('admin.growth.index') }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
</div>

<div class="alert alert-warning py-2 small" data-section="review-disclaimer">
    <strong>Review attempts are not confirmed App Store / Google Play reviews.</strong>
    Native review APIs do not reliably confirm whether a review was submitted.
    Rating or reviewing the app never grants XP, trophies or any other in-app benefit.
</div>

<div class="row g-4">
    {{-- Shares --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="shares">
            <div class="card-header"><strong>Shares</strong> <span class="text-muted small">(native share sheet opened — posting cannot be verified)</span></div>
            <table class="table table-sm mb-0">
                <tr><th>Total app shares</th><td>{{ $g['totals']['app_shares'] }}</td></tr>
                <tr><th>Total daily result shares</th><td>{{ $g['totals']['daily_result_shares'] }}</td></tr>
                <tr><th>Total high-score result shares (80+)</th><td>{{ $g['totals']['high_score_result_shares'] }} <span class="text-muted small">subset of daily result shares</span></td></tr>
                <tr><th>Total share rewards claimed</th><td>{{ $g['totals']['share_rewards_claimed'] }}</td></tr>
                <tr><th>Last {{ $g['window_days'] }} days shares</th><td><span class="badge bg-light text-dark">{{ $g['last_7_days']['shares'] }}</span> <span class="text-muted small">app + daily result</span></td></tr>
            </table>
            <div class="card-footer small text-muted">Share XP: first share +25 XP (First Share trophy), first daily result share per day +10 XP, first 80+ daily result share per day +15 XP (Daily Bragger trophy), 5 shares = Social Striker trophy. All limits are enforced server-side.</div>
        </div>
    </div>

    {{-- Review / rate attempts --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="reviews">
            <div class="card-header"><strong>Review attempts</strong> <span class="text-muted small">(attempts and taps — not confirmed reviews)</span></div>
            <table class="table table-sm mb-0">
                <tr><th>Total rate button taps</th><td>{{ $g['totals']['rate_button_taps'] }}</td></tr>
                <tr><th>Total review prompt attempts</th><td>{{ $g['totals']['review_prompt_attempts'] }} <span class="text-muted small">automatic prompts considered on the device</span></td></tr>
                <tr><th>Total native review requests</th><td>{{ $g['totals']['native_review_requests'] }} <span class="text-muted small">native dialog requested — the OS may not show it</span></td></tr>
                <tr><th>Last {{ $g['window_days'] }} days review attempts</th><td><span class="badge bg-light text-dark">{{ $g['last_7_days']['review_attempts'] }}</span> <span class="text-muted small">{{ $g['last_7_days']['review_prompt_attempts'] }} automatic prompt attempts + {{ $g['last_7_days']['rate_button_taps'] }} rate button taps</span></td></tr>
            </table>
            <div class="card-footer small text-muted">Review attempts are not confirmed App Store / Google Play reviews. Native review APIs do not reliably confirm whether a review was submitted.</div>
        </div>
    </div>

    {{-- Per-event table --}}
    <div class="col-12">
        <div class="card shadow-sm" data-section="events">
            <div class="card-header"><strong>All growth events</strong></div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Event</th><th>Name</th><th class="text-end">All time</th><th class="text-end">Last {{ $g['window_days'] }} days</th></tr></thead>
                <tbody>
                @foreach($g['events'] as $e)
                    <tr>
                        <td>{{ $e['label'] }}</td>
                        <td><code>{{ $e['name'] }}</code></td>
                        <td class="text-end">{{ $e['total'] }}</td>
                        <td class="text-end">{{ $e['last_7_days'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="card-footer small text-muted">Stored per event: name, user id, timestamp and at most a score / daily challenge id / trigger. The shared message, review text and the destination platform are never stored.</div>
        </div>
    </div>
</div>
@endsection
