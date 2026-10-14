@extends('admin.layout')

@section('title', 'Challenges')

@php
    $advancedKeys = ['difficulty', 'has_reveal', 'used_as_daily', 'tournament'];
    $filterKeys   = array_merge(['search', 'status', 'category', 'usage_pool', 'sort'], $advancedKeys);
    $advancedOpen = request()->hasAny($advancedKeys);
    $hasFilters   = request()->hasAny(array_diff($filterKeys, ['sort']));

    // Segments are shortcuts to existing filters (never exclusive categories:
    // a General photo appears under All, General AND Tournament when eligible).
    $segments = [
        ['label' => 'All',        'url' => '/admin/challenges',                     'count' => $summary['total'],               'active' => !$hasFilters],
        ['label' => 'Daily pool', 'url' => '/admin/challenges?usage_pool=daily',    'count' => $summary['daily_pool'],          'active' => request('usage_pool') === 'daily'],
        ['label' => 'Tournament', 'url' => '/admin/challenges?tournament=eligible', 'count' => $summary['tournament_eligible'], 'active' => request('tournament') === 'eligible'],
        ['label' => 'Pack',       'url' => '/admin/challenges?usage_pool=pack',     'count' => $summary['pack'],                'active' => request('usage_pool') === 'pack'],
        ['label' => 'General',    'url' => '/admin/challenges?usage_pool=general',  'count' => $summary['general'],             'active' => request('usage_pool') === 'general'],
        ['label' => 'Archived',   'url' => '/admin/challenges?status=archived',     'count' => $summary['archived'],            'active' => request('status') === 'archived'],
    ];
    $sortLabels = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'title' => 'Title A–Z'];
@endphp

@section('content')
@include('admin.partials.backup-reminder')

{{-- Page header --}}
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Challenges</h1>
        <div class="text-muted small">Manage challenge images, availability and status.</div>
    </div>
    <a href="/admin/challenges/create" class="btn btn-primary btn-sm text-nowrap">+ New Challenge</a>
</div>

{{-- Summary tiles — whole catalogue, backend counts. Pack and General are separate pools. --}}
<div class="row g-2 mb-3" data-section="summary">
    @foreach([
        ['label' => 'Total challenges',    'value' => $summary['total'],               'url' => '/admin/challenges',                     'class' => ''],
        ['label' => 'Daily pool',          'value' => $summary['daily_pool'],          'url' => '/admin/challenges?usage_pool=daily',    'class' => ''],
        ['label' => 'Tournament eligible', 'value' => $summary['tournament_eligible'], 'url' => '/admin/challenges?tournament=eligible', 'class' => $summary['low_tournament'] ? 'border-warning' : ''],
        ['label' => 'Pack',                'value' => $summary['pack'],                'url' => '/admin/challenges?usage_pool=pack',     'class' => ''],
        ['label' => 'General',             'value' => $summary['general'],             'url' => '/admin/challenges?usage_pool=general',  'class' => ''],
        ['label' => 'Used as Daily',       'value' => $summary['used_as_daily'],       'url' => '/admin/challenges?used_as_daily=yes',   'class' => ''],
    ] as $tile)
        <div class="col-6 col-md-4 col-lg-2">
            <a href="{{ $tile['url'] }}" class="admin-stat h-100 {{ $tile['class'] }}">
                <div class="admin-stat-value">{{ $tile['value'] }}</div>
                <div class="admin-stat-label">{{ $tile['label'] }}@if($tile['label'] === 'Used as Daily') <span class="text-muted">· excluded from tournaments</span>@endif</div>
            </a>
        </div>
    @endforeach
</div>

{{-- Low tournament-pool warning (v1.8.9 fairness) --}}
@if($summary['low_tournament'])
<div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3 small">
    <span aria-hidden="true">⚠️</span>
    <div>
        <strong>Low tournament pool — add more tournament challenges.</strong>
        Only {{ $summary['tournament_eligible'] }} tournament challenge{{ $summary['tournament_eligible'] === 1 ? ' is' : 's are' }} currently eligible;
        a 7-day tournament needs 7 unique photos (active, Tournament or General pool, never used as a Daily).
        @foreach($lowTournamentPools as $row)
            <span class="badge bg-light text-dark border ms-1">{{ $row['sport']->emoji }} {{ $row['sport']->name }}: {{ $row['count'] }}</span>
        @endforeach
        <div class="mt-1">Players see &ldquo;Not enough unused tournament challenges available&rdquo; until more eligible tournament photos are added.</div>
    </div>
</div>
@elseif($lowTournamentPools->isNotEmpty())
<div class="alert alert-warning py-2 small mb-3">
    ⚠️ Low tournament pool for:
    @foreach($lowTournamentPools as $row)
        <span class="badge bg-light text-dark border">{{ $row['sport']->emoji }} {{ $row['sport']->name }}: only {{ $row['count'] }} eligible</span>
    @endforeach
    — add more tournament challenges for that sport.
</div>
@endif

{{-- Segments: shortcuts to the most common pools --}}
<ul class="nav admin-segments border-bottom mb-3" data-section="segments">
    @foreach($segments as $seg)
        <li class="nav-item">
            <a class="nav-link {{ $seg['active'] ? 'active' : '' }}" href="{{ $seg['url'] }}" @if($seg['active']) aria-current="page" @endif>
                {{ $seg['label'] }} <span class="badge bg-light text-dark border ms-1">{{ $seg['count'] }}</span>
            </a>
        </li>
    @endforeach
</ul>

{{-- Filters: the common ones always visible, the rest behind "More filters" --}}
<form method="GET" action="/admin/challenges" class="mb-3" data-section="filters">
    <div class="row g-2 align-items-center">
        <div class="col-12 col-md-3">
            <label for="f-search" class="visually-hidden">Search challenges</label>
            <input id="f-search" type="search" name="search" value="{{ request('search') }}"
                   class="form-control form-control-sm" placeholder="Search challenges…">
        </div>
        <div class="col-6 col-md-2">
            <label for="f-status" class="visually-hidden">Status</label>
            <select id="f-status" name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Status: all</option>
                @foreach(['draft','active','archived'] as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-category" class="visually-hidden">Category</label>
            <select id="f-category" name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Category: all</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-usage" class="visually-hidden">Usage pool</label>
            <select id="f-usage" name="usage_pool" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Usage pool: all</option>
                <option value="daily"      {{ request('usage_pool') === 'daily'      ? 'selected' : '' }}>Daily</option>
                <option value="tournament" {{ request('usage_pool') === 'tournament' ? 'selected' : '' }}>Tournament</option>
                <option value="pack"       {{ request('usage_pool') === 'pack'       ? 'selected' : '' }}>Pack</option>
                <option value="general"    {{ request('usage_pool') === 'general'    ? 'selected' : '' }}>General</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#more-filters"
                    aria-expanded="{{ $advancedOpen ? 'true' : 'false' }}" aria-controls="more-filters">
                More filters {{ $advancedOpen ? '▲' : '▼' }}
            </button>
        </div>
        @if($hasFilters)
        <div class="col-auto">
            <a href="/admin/challenges" class="btn btn-link btn-sm">Reset filters</a>
        </div>
        @endif
    </div>
    <div class="collapse {{ $advancedOpen ? 'show' : '' }} mt-2" id="more-filters">
        <div class="row g-2 align-items-center">
            <div class="col-6 col-md-2">
                <label for="f-difficulty" class="visually-hidden">Difficulty</label>
                <select id="f-difficulty" name="difficulty" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Difficulty: all</option>
                    @foreach(['easy','medium','hard'] as $d)
                        <option value="{{ $d }}" {{ request('difficulty') === $d ? 'selected' : '' }}>{{ ucfirst($d) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="f-reveal" class="visually-hidden">Reveal image</label>
                <select id="f-reveal" name="has_reveal" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Reveal image: all</option>
                    <option value="yes" {{ request('has_reveal') === 'yes' ? 'selected' : '' }}>Has reveal</option>
                    <option value="no"  {{ request('has_reveal') === 'no'  ? 'selected' : '' }}>No reveal</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="f-used" class="visually-hidden">Used as Daily</label>
                <select id="f-used" name="used_as_daily" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Used as Daily: all</option>
                    <option value="yes" {{ request('used_as_daily') === 'yes' ? 'selected' : '' }}>Used as Daily</option>
                    <option value="no"  {{ request('used_as_daily') === 'no'  ? 'selected' : '' }}>Not used as Daily</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="f-tournament" class="visually-hidden">Tournament eligibility</label>
                <select id="f-tournament" name="tournament" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Tournament: all</option>
                    <option value="eligible" {{ request('tournament') === 'eligible' ? 'selected' : '' }}>Tournament eligible</option>
                    <option value="blocked"  {{ request('tournament') === 'blocked'  ? 'selected' : '' }}>Tournament blocked</option>
                </select>
            </div>
        </div>
    </div>
</form>

@php
    $tableHead = '<tr>
        <th style="width:148px">Images <span class="text-muted fw-normal" title="H = hidden image (what players see), R = reveal image">H · R</span></th>
        <th>Title</th><th>Availability</th><th>Readiness</th><th>Difficulty</th><th>Status</th>
        <th title="Ball position: X%, Y% of the image (0..1 ratios stored)">Ball</th><th class="text-end">Actions</th>
    </tr>';
    $total = $challenges->total();
@endphp

{{-- Section 1: Available challenges (never used as a Daily) --}}
<div class="d-flex justify-content-between align-items-end mb-2 gap-3 flex-wrap" data-section="available">
    <div>
        <h2 class="h5 mb-0">{{ $total }} {{ $total === 1 ? 'challenge' : 'challenges' }}
            @if($hasFilters)<span class="text-muted small fw-normal">matching the current filters</span>@endif
        </h2>
        <div class="text-muted small">Not yet used as a Daily — usable for Daily scheduling, tournaments and packs (per pool).</div>
    </div>
    <div class="d-flex align-items-center gap-2 small">
        <label for="f-sort" class="text-muted mb-0">Sort by</label>
        <select id="f-sort" name="sort" form="challenge-sort" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            @foreach($sortLabels as $value => $label)
                <option value="{{ $value }}" {{ $sort === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
{{-- Sort submits the current filters plus the chosen order (GET, no JS needed beyond onchange). --}}
<form id="challenge-sort" method="GET" action="/admin/challenges" class="d-none">
    @foreach(request()->only(array_diff($filterKeys, ['sort'])) as $k => $v)
        @if($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-challenges mb-0 align-middle">
            <thead class="table-light">{!! $tableHead !!}</thead>
            <tbody>
                @forelse($challenges as $challenge)
                    @include('admin.challenges._row', ['challenge' => $challenge])
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        @if(request('used_as_daily') === 'yes')
                            Filter is set to <strong>Used as Daily</strong> — see the Used Daily section below.
                        @elseif($hasFilters)
                            No available challenges match these filters<span>@if(request('search')) for &ldquo;{{ request('search') }}&rdquo;@endif</span>.
                            <a href="/admin/challenges">Clear filters</a>
                        @else
                            No available challenges yet. <a href="/admin/challenges/create">Create one.</a>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($challenges->hasPages())
<div class="mt-3">
    {{ $challenges->links() }}
</div>
@endif

{{-- Section 2: Used Daily challenges (collapsed; permanently tournament-excluded) --}}
@if(isset($usedDaily))
<details class="mt-4" data-section="used-daily" {{ !empty($usedDailyOpen) ? 'open' : '' }}>
    <summary class="h5 mb-1" style="cursor:pointer">
        🔒 Used Daily challenges <span class="badge bg-danger">{{ $usedDaily->total() }}</span>
    </summary>
    <p class="text-muted small mb-2">These photos were already used as Daily Challenges and are excluded from new tournaments.</p>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-challenges mb-0 align-middle">
                <thead class="table-light">{!! $tableHead !!}</thead>
                <tbody>
                    @forelse($usedDaily as $challenge)
                        @include('admin.challenges._row', ['challenge' => $challenge])
                    @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No Used Daily challenges match the current filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($usedDaily->hasPages())
    <div class="mt-3">
        {{ $usedDaily->links() }}
    </div>
    @endif
</details>
@endif
@endsection
