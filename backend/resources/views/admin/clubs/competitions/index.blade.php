@extends('admin.layout')

@section('title', 'Club competitions')

@php $f = $filters; @endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Club competitions</h1>
        <div class="text-muted small">Private competitions of every club. Create and edit them from the club page → Competitions tab.</div>
    </div>
</div>

<form method="GET" action="{{ route('admin.club-competitions.index') }}" class="card shadow-sm mb-3" data-section="filters" role="search">
    <div class="card-body py-3">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-lg-5">
                <label for="cc-q" class="visually-hidden">Search competitions</label>
                <input id="cc-q" type="search" name="q" value="{{ $f['q'] }}" maxlength="100" class="form-control" placeholder="Search name, ID or public ID…" autocomplete="off">
            </div>
            <div class="col-6 col-lg-3">
                <label for="cc-club" class="visually-hidden">Club</label>
                <select id="cc-club" name="club" class="form-select">
                    <option value="">All clubs</option>
                    @foreach($clubs as $c)
                        <option value="{{ $c->id }}" @selected($f['club'] === (string) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="cc-phase" class="visually-hidden">Phase</label>
                <select id="cc-phase" name="phase" class="form-select">
                    <option value="">Any phase</option>
                    @foreach($phases as $p)
                        <option value="{{ $p }}" @selected($f['phase'] === $p)>{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Search</button>
                @if($isFiltered)<a href="{{ route('admin.club-competitions.index') }}" class="btn btn-outline-secondary">Clear</a>@endif
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>Competition</th>
                    <th>Club</th>
                    <th>Phase</th>
                    <th>Window (club time)</th>
                    <th>Replay rules</th>
                    <th class="text-end">Packs</th>
                    <th class="text-end">Players</th>
                </tr>
            </thead>
            <tbody>
            @forelse($competitions as $c)
                <tr>
                    <td>
                        <a class="fw-semibold club-name" href="{{ route('admin.clubs.competitions.show', [$c->club_id, $c]) }}">{{ $c->name }}</a>
                        <div class="text-muted small font-monospace">#{{ $c->id }} · {{ $c->public_id }}</div>
                    </td>
                    <td class="small"><a href="{{ route('admin.clubs.show', ['club' => $c->club_id, 'tab' => 'competitions']) }}">{{ $c->club?->name }}</a></td>
                    <td class="text-nowrap">@include('admin.clubs.competitions._phase', ['competition' => $c])</td>
                    <td class="small text-nowrap">
                        {{ $c->starts_at?->copy()->setTimezone($c->timezone)->format('Y-m-d H:i') }}
                        – {{ $c->ends_at?->copy()->setTimezone($c->timezone)->format('Y-m-d H:i') }}
                        <div class="text-muted" style="font-size:.7rem">{{ $c->timezone }}</div>
                    </td>
                    <td class="small">
                        {{ str_replace('_', ' ', $c->replay_mode->value) }}@if($c->replay_mode->value === 'multiple_attempts') · max {{ $c->max_attempts }} · {{ $c->score_rule->value }} counts @endif
                        <div class="text-muted" style="font-size:.7rem">normal play {{ $c->normal_play_policy->value }}</div>
                    </td>
                    <td class="text-end small">{{ $c->competition_packs_count }}</td>
                    <td class="text-end small">{{ $c->participants_count }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">
                    @if($isFiltered) No competitions match. <a href="{{ route('admin.club-competitions.index') }}">Clear filters</a>
                    @else No club competitions yet ({{ $total }}). Create one from a club's Competitions tab. @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($competitions->hasPages())<div class="mt-3">{{ $competitions->links('pagination::bootstrap-5') }}</div>@endif
<p class="small text-muted mt-2 mb-0">Showing {{ $competitions->firstItem() ?? 0 }}–{{ $competitions->lastItem() ?? 0 }} of {{ $competitions->total() }}.</p>
@endsection
