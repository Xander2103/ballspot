@extends('club-portal.layout')

@section('title', $competition->name . ' · ' . $club->name)

@php
    $tz = $competition->timezone;
    $phase = $competition->phase()->value;
    $at = fn ($dt) => $dt ? $dt->copy()->setTimezone($tz)->format('Y-m-d H:i') : '—';
    $canPublish = $canManage && $phase === 'draft';
    $canCancel  = $canManage && in_array($phase, ['draft', 'upcoming'], true);
    $canExtend  = $canManage && $phase === 'active';
@endphp

@section('content')
<div class="mb-2"><a href="{{ route('club-portal.competitions.index', $club) }}" class="text-muted small">&larr; {{ __('club_portal.competitions.back') }}</a></div>

<div class="d-flex flex-wrap align-items-start gap-3 mb-3" data-section="competition-header">
    <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0">{{ $competition->name }}</h1>
            @include('club-portal.competitions._phase')
        </div>
        <div class="text-muted small mt-1">{{ $at($competition->starts_at) }} – {{ $at($competition->ends_at) }} ({{ $tz }}) · {{ __('club_portal.competitions.participants', ['count' => (int) $competition->participants_count]) }}</div>
        <div class="text-muted small">{{ __('club_portal.competitions.platform_note') }}</div>
    </div>
    @if($canPublish)
        <form method="POST" action="{{ route('club-portal.competitions.publish', [$club, $competition]) }}" data-section="publish">
            @csrf
            <input type="hidden" name="version" value="{{ $competition->version }}">
            <button type="submit" class="btn btn-success btn-sm">{{ __('club_portal.competitions.publish') }}</button>
            <div class="form-text" style="max-width: 18rem">{{ __('club_portal.competitions.publish_hint') }}</div>
        </form>
    @endif
</div>

@if($canCancel || $canExtend)
<div class="row g-3 mb-3">
    @if($canExtend)
        <div class="col-md-6">
            <form method="POST" action="{{ route('club-portal.competitions.extend', [$club, $competition]) }}" class="portal-card p-2" data-section="extend">
                @csrf
                <input type="hidden" name="version" value="{{ $competition->version }}">
                <label for="extend-ends-at" class="form-label small fw-semibold mb-1">{{ __('club_portal.competitions.extend_label') }}</label>
                <div class="input-group input-group-sm">
                    <input type="datetime-local" id="extend-ends-at" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" required value="{{ $competition->ends_at->copy()->setTimezone($tz)->addDay()->format('Y-m-d\TH:i') }}">
                    <button class="btn btn-outline-primary" type="submit">{{ __('club_portal.competitions.extend') }}</button>
                </div>
                <div class="form-text">{{ __('club_portal.competitions.extend_hint') }}</div>
            </form>
        </div>
    @endif
    @if($canCancel)
        <div class="col-md-6">
            <form method="POST" action="{{ route('club-portal.competitions.cancel', [$club, $competition]) }}" class="portal-card p-2" data-section="cancel">
                @csrf
                <input type="hidden" name="version" value="{{ $competition->version }}">
                <label for="cancel-reason" class="form-label small fw-semibold mb-1">{{ __('club_portal.competitions.cancel') }}</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="cancel-reason" name="reason" maxlength="200" class="form-control" placeholder="{{ __('club_portal.competitions.cancel_reason') }}">
                    <button class="btn btn-outline-danger" type="submit">{{ __('club_portal.competitions.cancel') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
@endif

<ul class="nav nav-tabs mb-3" data-section="competition-tabs">
    @foreach(['rules' => 'tab_rules', 'standings' => 'tab_standings'] as $key => $label)
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('club-portal.competitions.show', [$club, $competition, 'tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ __('club_portal.competitions.' . $label) }}</a>
        </li>
    @endforeach
</ul>

@if($tab === 'rules')
    @if(!$canEdit)
        <div class="alert alert-secondary py-2 small" data-section="locked-note">{{ $competition->isLocked() && in_array($phase, ['active'], true) ? __('club_portal.competitions.locked_note') : __('club_portal.competitions.closed_note') }}</div>
    @endif
    <form method="POST" action="{{ route('club-portal.competitions.update', [$club, $competition]) }}" class="portal-card p-3" style="max-width: 860px" data-section="competition-editor">
        @csrf @method('PUT')
        <fieldset @disabled(!$canEdit || !$canManage)>
            @include('club-portal.competitions._form')
            @if($canEdit && $canManage)
                <button type="submit" class="btn btn-primary">{{ $phase === 'draft' ? __('club_portal.competitions.save_draft') : __('club_portal.competitions.save') }}</button>
            @endif
        </fieldset>
    </form>
@else
    <div class="portal-card" data-section="standings">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>{{ __('club_portal.competitions.col_rank') }}</th>
                        <th>{{ __('club_portal.competitions.col_player') }}</th>
                        <th class="text-end">{{ __('club_portal.competitions.col_total') }}</th>
                        <th class="text-end">{{ __('club_portal.competitions.col_completed') }}</th>
                        <th class="text-end">{{ __('club_portal.competitions.col_attempts') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($standings as $row)
                    <tr>
                        <td class="fw-bold">{{ $row['rank'] }}</td>
                        <td>{{ $row['username'] }}</td>
                        <td class="text-end">{{ number_format($row['total_score']) }}</td>
                        <td class="text-end">{{ $row['packs_completed'] }}</td>
                        <td class="text-end">{{ $row['attempts_used'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ __('club_portal.competitions.standings_empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($standings->hasPages())<div class="mt-3">{{ $standings->links('pagination::bootstrap-5') }}</div>@endif
@endif
@endsection
