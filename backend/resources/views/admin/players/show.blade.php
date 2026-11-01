@extends('admin.layout')

@section('title', 'Player #' . $player->id)

@php
    $fmt = fn ($dt) => $dt ? \Illuminate\Support\Carbon::parse($dt)->format('Y-m-d H:i') : '—';
@endphp

@section('content')
<p class="mb-2"><a href="{{ route('admin.players.index') }}">‹ Players</a></p>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ $player->username }} <span class="text-muted fw-normal">#{{ $player->id }}</span></h1>
    <div data-section="contact">
        @if($d['contact_url'])
            <a class="btn btn-primary btn-sm" href="{{ $d['contact_url'] }}">Email this player</a>
        @elseif($player->anonymized_at)
            <span class="text-muted small">Deleted account: no contact details are kept.</span>
        @else
            <span class="text-muted small">Email not verified, so this address should not be used to contact the player.</span>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100" data-section="account">
            <div class="card-header"><strong>Account</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>User ID</th><td>{{ $player->id }}</td></tr>
                <tr><th>Username</th><td>{{ $player->username }}</td></tr>
                <tr><th>Entered name</th><td>{{ $player->name }}</td></tr>
                <tr><th>Email</th><td>{{ $player->email }}</td></tr>
                <tr><th>Email verified</th><td>{{ $fmt($player->email_verified_at) }}</td></tr>
                <tr><th>Registered</th><td>{{ $fmt($player->created_at) }}</td></tr>
                <tr><th>Status</th><td>@include('admin.players._status', ['player' => $player])</td></tr>
                @if($player->anonymized_at)
                    <tr><th>Deleted</th><td>{{ $fmt($player->anonymized_at) }}</td></tr>
                @endif
                <tr><th>Language</th><td>{{ $player->preferred_language ?? '—' }}</td></tr>
                <tr><th>Login code (2FA)</th><td>{{ $player->two_factor_enabled ? 'On' : 'Off' }}</td></tr>
            </table>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm h-100" data-section="daily">
            <div class="card-header"><strong>Daily</strong></div>
            <table class="table table-sm mb-0">
                <tr><th>Dailies played</th><td>{{ $d['daily']['played'] }}</td></tr>
                <tr><th>Total score</th><td>{{ $d['daily']['total_score'] }}</td></tr>
                <tr><th>Average score</th><td>{{ $d['daily']['avg_score'] ?? '—' }}</td></tr>
                <tr><th>Best score</th><td>{{ $d['daily']['best_score'] ?? '—' }}</td></tr>
                <tr><th>Current streak</th><td>{{ $d['daily']['current_streak'] }}</td></tr>
                <tr><th>Best streak</th><td>{{ $d['daily']['best_streak'] }}</td></tr>
                <tr><th>Last played</th><td>{{ $fmt($d['daily']['last_played_at']) }}</td></tr>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="competition">
            <div class="card-header"><strong>Competition results</strong></div>
            @if($d['competition']['current'])
                <div class="card-body py-2 small">Now: #{{ $d['competition']['current']['placement'] }} in {{ $d['competition']['current']['period_label'] }} with {{ $d['competition']['current']['total_score'] }} points (live, not final).</div>
            @endif
            <table class="table table-sm mb-0">
                <thead><tr><th>Period</th><th>#</th><th>Of</th><th class="text-end">Points</th><th class="text-end">XP</th></tr></thead>
                <tbody>
                @forelse($d['competition']['finishes'] as $f)
                    <tr @class(['table-warning' => $f->placement <= 3])>
                        <td>{{ $f->period_label }}</td><td>{{ $f->placement }}</td><td>{{ $f->total_players }}</td>
                        <td class="text-end">{{ $f->total_score }}</td><td class="text-end">{{ $f->xp_awarded }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No closed competition placements.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="card-footer small text-muted">Highlighted rows are podium places. These are virtual placements: BallPicker stores no prize or payout records.</div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="tournaments">
            <div class="card-header"><strong>Tournaments</strong> <span class="text-muted small">— {{ $d['tournaments']['total'] }} joined</span></div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Tournament</th><th>Status</th><th>Joined</th><th>#</th><th class="text-end">Points</th></tr></thead>
                <tbody>
                @forelse($d['tournaments']['rows'] as $t)
                    <tr>
                        <td>{{ $t['name'] }}</td><td>{{ $t['status'] }}</td><td class="text-nowrap">{{ $t['joined_at'] ? \Illuminate\Support\Carbon::parse($t['joined_at'])->format('Y-m-d') : '—' }}</td>
                        <td>{{ $t['finish']?->placement ?? '—' }}</td><td class="text-end">{{ $t['finish']?->total_score ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No tournaments joined.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="xp">
            <div class="card-header"><strong>XP</strong> <span class="text-muted small">— {{ $d['xp']['rank']['total_xp'] }} XP · {{ $d['xp']['rank']['name'] }} (level {{ $d['xp']['rank']['level'] }})</span></div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Latest awards</th><th class="text-end">XP</th><th>Date</th></tr></thead>
                <tbody>
                @forelse($d['xp']['events'] as $e)
                    <tr><td>{{ $e->reason ?: $e->source_type }}</td><td class="text-end">{{ $e->amount }}</td><td class="text-nowrap">{{ $fmt($e->created_at) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-muted">No XP events.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100" data-section="badges">
            <div class="card-header"><strong>Badges</strong> <span class="text-muted small">— {{ count($d['badges']) }} earned</span></div>
            <table class="table table-sm mb-0">
                <thead><tr><th>Badge</th><th>Rarity</th><th>Earned</th></tr></thead>
                <tbody>
                @forelse($d['badges'] as $b)
                    <tr><td>{{ $b['name'] }}</td><td>{{ $b['rarity'] }}</td><td class="text-nowrap">{{ $fmt($b['earned_at']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-muted">No badges earned.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
