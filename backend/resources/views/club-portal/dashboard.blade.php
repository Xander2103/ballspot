@extends('club-portal.layout')

@section('title', __('club_portal.dashboard.title') . ' · ' . $club->name)

@section('content')
@php
    $m = $stats['members'];
    $p = $stats['plays'];
    $tz = $club->timezone ?? config('app.timezone');
    $hasCompetitionPages = \Illuminate\Support\Facades\Route::has('club-portal.competitions.show');
    $photoBadge = ['pending' => 'badge-soft-warn', 'in_review' => 'badge-soft-info', 'in_preparation' => 'badge-soft-info', 'converted' => 'badge-soft-ok', 'rejected' => 'badge-soft-danger'];
@endphp
<h1 class="visually-hidden">{{ __('club_portal.dashboard.title') }}</h1>
<div class="row g-3 mb-4" data-section="stats">
    <div class="col-6 col-lg-3">
        <div class="portal-card p-3 h-100">
            <div class="stat-label">{{ __('club_portal.dashboard.members') }}</div>
            <div class="stat-value">{{ number_format($m['total']) }} <span class="fs-6 text-muted fw-normal">{{ __('club_portal.dashboard.capacity', ['max' => number_format($m['capacity'])]) }}</span></div>
            <div class="small text-muted">{{ __('club_portal.dashboard.members_hint', ['count' => $m['active_30d']]) }}</div>
            <div class="small text-muted">{{ __('club_portal.dashboard.joins_30d', ['count' => $m['joins_30d']]) }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="portal-card p-3 h-100">
            <div class="stat-label">{{ __('club_portal.dashboard.plays_30d') }}</div>
            <div class="stat-value">{{ number_format($p['plays_30d']) }}</div>
            <div class="small text-muted">{{ __('club_portal.dashboard.players_30d', ['count' => $p['players_30d']]) }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="portal-card p-3 h-100">
            <div class="stat-label">{{ __('club_portal.dashboard.packs_active') }}</div>
            <div class="stat-value">{{ $stats['packs']['active'] }}</div>
            <div class="small text-muted">{{ __('club_portal.dashboard.codes_usable') }}: {{ $stats['codes']['usable'] }}</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="portal-card p-3 h-100">
            <div class="stat-label">{{ __('club_portal.dashboard.photos_open') }}</div>
            <div class="stat-value">{{ $stats['photos']['open'] }}</div>
            <a href="{{ route('club-portal.photos.index', $club) }}" class="small">{{ __('club_portal.nav.photos') }} →</a>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="portal-card p-3 mb-3" data-section="live-competitions">
            <h2 class="h6">{{ __('club_portal.dashboard.live_competitions') }} <span class="badge badge-soft">{{ $stats['competitions']['live_count'] }}</span></h2>
            @forelse($stats['competitions']['live'] as $c)
                <div class="d-flex justify-content-between border-top py-2 small">
                    <span>@if($hasCompetitionPages)<a href="{{ route('club-portal.competitions.show', [$club, $c]) }}">{{ $c->name }}</a>@else{{ $c->name }}@endif</span>
                    <span class="text-muted">{{ __('club_portal.dashboard.participants', ['count' => (int) $c->participants_count]) }} · {{ __('club_portal.dashboard.ends', ['date' => $c->ends_at?->copy()->setTimezone($tz)->format('Y-m-d H:i')]) }}</span>
                </div>
            @empty
                <p class="small text-muted mb-0">{{ __('club_portal.dashboard.no_live') }}</p>
            @endforelse
        </div>
        <div class="portal-card p-3 mb-3" data-section="upcoming-competitions">
            <h2 class="h6">{{ __('club_portal.dashboard.upcoming_competitions') }} <span class="badge badge-soft">{{ $stats['competitions']['upcoming'] }}</span>
                @if($stats['competitions']['drafts'] > 0)<span class="small text-muted fw-normal ms-1">{{ trans_choice('club_portal.dashboard.drafts', $stats['competitions']['drafts'], ['count' => $stats['competitions']['drafts']]) }}</span>@endif
            </h2>
            @forelse($upcoming as $c)
                <div class="d-flex justify-content-between border-top py-2 small">
                    <span>@if($hasCompetitionPages)<a href="{{ route('club-portal.competitions.show', [$club, $c]) }}">{{ $c->name }}</a>@else{{ $c->name }}@endif</span>
                    <span class="text-muted">{{ __('club_portal.dashboard.starts', ['date' => $c->starts_at?->copy()->setTimezone($tz)->format('Y-m-d H:i')]) }}</span>
                </div>
            @empty
                <p class="small text-muted mb-0">{{ __('club_portal.dashboard.no_upcoming') }}</p>
            @endforelse
        </div>
        <div class="portal-card p-3" data-section="photo-statuses">
            <h2 class="h6">{{ __('club_portal.dashboard.photo_statuses') }}</h2>
            <div class="d-flex flex-wrap gap-2">
                @foreach($photoCounts as $status => $count)
                    <a href="{{ route('club-portal.photos.index', ['club' => $club, 'status' => $status]) }}" class="badge {{ $photoBadge[$status] ?? 'badge-soft' }} text-decoration-none">{{ __('club_portal.photo_status.' . $status) }}: {{ $count }}</a>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="portal-card p-3" data-section="recent-activity">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">{{ __('club_portal.dashboard.recent_activity') }}</h2>
                <a href="{{ route('club-portal.audit.index', $club) }}" class="small">{{ __('club_portal.dashboard.view_all') }} →</a>
            </div>
            @include('club-portal._events', ['events' => $events, 'compact' => true])
        </div>
    </div>
</div>
@endsection
