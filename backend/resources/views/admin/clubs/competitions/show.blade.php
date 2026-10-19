@extends('admin.layout')

@section('title', $competition->name . ' · ' . $club->name)

@php
    $tz = $competition->timezone;
    $phase = $competition->phase()->value;
    $at = fn ($dt) => $dt ? $dt->copy()->setTimezone($tz)->format('Y-m-d H:i') : '—';
    $canPublish  = $phase === 'draft';
    $canCancel   = in_array($phase, ['draft', 'upcoming'], true);
    $canExtend   = $phase === 'active';
    $canVoid     = in_array($phase, ['active', 'ended', 'finalized'], true);
    $canRebuild  = in_array($phase, ['active', 'ended', 'finalized', 'voided'], true);
    $canFinalize = $phase === 'ended';
@endphp

@section('content')
<div class="mb-2"><a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'competitions']) }}" class="text-muted small">&larr; Back to {{ $club->name }}</a></div>

<div class="d-flex flex-wrap align-items-start gap-3 mb-3" data-section="competition-header">
    <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0">Competition: {{ $competition->name }}</h1>
            @include('admin.clubs.competitions._phase', ['competition' => $competition])
        </div>
        <div class="text-muted small mt-1">
            {{ $at($competition->starts_at) }} – {{ $at($competition->ends_at) }} ({{ $tz }})
            · {{ $competition->participants_count }} {{ $competition->participants_count === 1 ? 'player' : 'players' }}
            · <a href="{{ route('admin.clubs.show', $club) }}">{{ $club->name }}</a>
        </div>
        <div class="text-muted small font-monospace">#{{ $competition->id }} · {{ $competition->public_id }} · v{{ $competition->version }}</div>
        @if($competition->rules_locked_at)<div class="small mt-1">🔒 Rules locked {{ $at($competition->rules_locked_at) }}</div>@endif
        @if($phase === 'voided')<div class="small mt-1"><span class="badge badge-archived">Voided {{ $at($competition->voided_at) }}</span> {{ $competition->void_reason }}</div>@endif
        @if($phase === 'finalized')<div class="small mt-1">Finalized {{ $at($competition->finalized_at) }} — club trophies only, no global XP.</div>@endif
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-start" data-section="competition-actions">
        @if($canPublish)
            <form method="POST" action="{{ route('admin.clubs.competitions.publish', [$club, $competition]) }}">@csrf
                <input type="hidden" name="version" value="{{ $competition->version }}">
                <button type="submit" class="btn btn-success btn-sm">Publish</button>
            </form>
        @endif
        @if($canFinalize)
            <form method="POST" action="{{ route('admin.clubs.competitions.finalize', [$club, $competition]) }}">@csrf
                <button type="submit" class="btn btn-primary btn-sm">Finalize now</button>
            </form>
        @endif
        @if($canRebuild)
            <form method="POST" action="{{ route('admin.clubs.competitions.rebuild', [$club, $competition]) }}">@csrf
                <input type="hidden" name="dry_run" value="1">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Rebuild standings…</button>
            </form>
        @endif
    </div>
</div>

@if($canCancel || $canExtend || $canVoid)
<div class="row g-3 mb-3" data-section="competition-danger">
    @if($canExtend)
    <div class="col-md-4">
        <form method="POST" action="{{ route('admin.clubs.competitions.extend', [$club, $competition]) }}" class="card card-body py-2 shadow-sm">@csrf
            <input type="hidden" name="version" value="{{ $competition->version }}">
            <label for="extend-ends-at" class="form-label small fw-semibold mb-1">Extend end (club time)</label>
            <div class="input-group input-group-sm">
                <input type="datetime-local" id="extend-ends-at" name="ends_at" class="form-control" required value="{{ $competition->ends_at->copy()->setTimezone($tz)->addDay()->format('Y-m-d\TH:i') }}">
                <button class="btn btn-outline-primary" type="submit">Extend end</button>
            </div>
            <div class="form-text">Later only; audited (D20).</div>
        </form>
    </div>
    @endif
    @if($canCancel)
    <div class="col-md-4">
        <form method="POST" action="{{ route('admin.clubs.competitions.cancel', [$club, $competition]) }}" class="card card-body py-2 shadow-sm">@csrf
            <input type="hidden" name="version" value="{{ $competition->version }}">
            <label for="cancel-reason" class="form-label small fw-semibold mb-1">Cancel competition</label>
            <div class="input-group input-group-sm">
                <input type="text" id="cancel-reason" name="reason" maxlength="200" class="form-control" placeholder="Reason (optional)">
                <button class="btn btn-outline-danger" type="submit">Cancel competition</button>
            </div>
        </form>
    </div>
    @endif
    @if($canVoid)
    <div class="col-md-4">
        <form method="POST" action="{{ route('admin.clubs.competitions.void', [$club, $competition]) }}" class="card card-body py-2 shadow-sm">@csrf
            <input type="hidden" name="version" value="{{ $competition->version }}">
            <label for="void-reason" class="form-label small fw-semibold mb-1">Void competition</label>
            <div class="input-group input-group-sm">
                <input type="text" id="void-reason" name="reason" maxlength="200" class="form-control @error('reason') is-invalid @enderror" placeholder="Reason (required)" required>
                <button class="btn btn-danger" type="submit">Void…</button>
            </div>
            <div class="form-text">Removes it from every board; club trophies are withdrawn.</div>
        </form>
    </div>
    @endif
</div>
@endif

<ul class="nav nav-tabs club-tabs mb-3" role="tablist" data-section="competition-tabs">
    @foreach(['rules' => 'Rules & packs', 'standings' => 'Standings'] as $key => $label)
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('admin.clubs.competitions.show', [$club, $competition, 'tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
                {{ $label }}@if($key === 'standings') <span class="badge bg-light text-dark border ms-1">{{ $competition->participants_count }}</span>@endif
            </a>
        </li>
    @endforeach
</ul>

@if($tab === 'rules')
<form method="POST" action="{{ route('admin.clubs.competitions.update', [$club, $competition]) }}" class="card shadow-sm" style="max-width: 860px;" data-section="competition-editor">
    @csrf @method('PUT')
    <div class="card-body">
        @if($locked)
            <div class="alert alert-secondary py-2 small" data-section="locked-note">🔒 The competition has started: replay rules, packs, start time and normal-play policy are read-only. Name and description stay editable.</div>
        @endif
        @include('admin.clubs.competitions._form')
        <button type="submit" class="btn btn-primary">{{ $phase === 'draft' ? 'Save draft' : 'Save changes' }}</button>
    </div>
</form>
@else
    @include('admin.clubs.competitions._standings')
@endif
@endsection
