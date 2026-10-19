@extends('admin.layout')

@section('title', 'Clubs')

@php
    $f = $filters;
    $statusSelected = $f['status'];
    $segments = [
        ['label' => 'All',         'url' => '/admin/clubs',                      'count' => $counts['all'],         'active' => !$isFiltered],
        ['label' => 'Active',      'url' => '/admin/clubs?status%5B%5D=active',    'count' => $counts['active'],      'active' => $statusSelected === ['active'] && !$f['needs_owner']],
        ['label' => 'Suspended',   'url' => '/admin/clubs?status%5B%5D=suspended', 'count' => $counts['suspended'],   'active' => $statusSelected === ['suspended']],
        ['label' => 'Archived',    'url' => '/admin/clubs?status%5B%5D=archived',  'count' => $counts['archived'],    'active' => $statusSelected === ['archived']],
        ['label' => 'Needs owner', 'url' => '/admin/clubs?needs_owner=1',          'count' => $counts['needs_owner'], 'active' => $f['needs_owner'] && $statusSelected === []],
    ];
    $typeLabels = ['sports_club' => 'Sports club', 'school' => 'School', 'company' => 'Company', 'community' => 'Community', 'other' => 'Other'];
    $moreOpen = $f['sport'] !== '' || $f['club_type'] !== '' || $f['country'] !== '' || $f['has_active_competition'] || $f['has_pending_photos']
        || $f['needs_owner'] || $f['created_from'] !== '' || $f['created_to'] !== '' || $statusSelected !== [];
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3 gap-3">
    <div>
        <h1 class="h4 mb-0">Clubs</h1>
        <div class="text-muted small">Private clubs: members, invite codes, club packs and competitions. There is no public club directory in the app.</div>
    </div>
    <a href="{{ route('admin.clubs.create') }}" class="btn btn-primary btn-sm text-nowrap">+ Create club</a>
</div>

<ul class="nav admin-segments border-bottom mb-3" data-section="segments">
    @foreach($segments as $seg)
        <li class="nav-item">
            <a class="nav-link {{ $seg['active'] ? 'active' : '' }}" href="{{ $seg['url'] }}" @if($seg['active']) aria-current="page" @endif>
                {{ $seg['label'] }} <span class="badge {{ $seg['label'] === 'Needs owner' && $seg['count'] > 0 ? 'badge-needs-owner' : 'bg-light text-dark border' }} ms-1">{{ $seg['count'] }}</span>
            </a>
        </li>
    @endforeach
</ul>

<form method="GET" action="{{ route('admin.clubs.index') }}" class="card shadow-sm mb-3" data-section="filters" role="search">
    <div class="card-body py-3">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-lg-6">
                <label for="club-q" class="visually-hidden">Search clubs</label>
                <input id="club-q" type="search" name="q" value="{{ $f['q'] }}" maxlength="100" class="form-control"
                       placeholder="Search name, slug, city, ref, ID, invite code, owner…" autocomplete="off">
            </div>
            <div class="col-6 col-lg-2">
                <label for="club-sort" class="visually-hidden">Sort</label>
                <select id="club-sort" name="sort" class="form-select">
                    @foreach($sorts as $value => $label)
                        <option value="{{ $value }}" {{ $f['sort'] === $value ? 'selected' : '' }}>Sort: {{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="club-per-page" class="visually-hidden">Per page</label>
                <select id="club-per-page" name="per_page" class="form-select">
                    @foreach(\App\Services\Clubs\AdminClubIndexQuery::PER_PAGES as $n)
                        <option value="{{ $n }}" {{ $f['per_page'] === $n ? 'selected' : '' }}>{{ $n }} per page</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Search</button>
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#club-more-filters"
                        aria-expanded="{{ $moreOpen ? 'true' : 'false' }}" aria-controls="club-more-filters" title="More filters">Filters</button>
            </div>
        </div>
        <div class="collapse {{ $moreOpen ? 'show' : '' }}" id="club-more-filters">
            <div class="row g-2 align-items-end mt-1">
                <div class="col-12 col-md-auto">
                    <div class="small text-muted mb-1">Status</div>
                    @foreach(['active' => 'Active', 'suspended' => 'Suspended', 'archived' => 'Archived'] as $value => $label)
                        <div class="form-check form-check-inline mb-0">
                            <input class="form-check-input" type="checkbox" name="status[]" value="{{ $value }}" id="st-{{ $value }}" {{ in_array($value, $statusSelected, true) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="st-{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-sport" class="small text-muted mb-1">Sport</label>
                    <select id="f-sport" name="sport" class="form-select form-select-sm">
                        <option value="">Any sport</option>
                        @foreach($sports as $sport)
                            <option value="{{ $sport->id }}" {{ $f['sport'] === (string) $sport->id ? 'selected' : '' }}>{{ $sport->emoji }} {{ $sport->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-type" class="small text-muted mb-1">Type</label>
                    <select id="f-type" name="club_type" class="form-select form-select-sm">
                        <option value="">Any type</option>
                        @foreach($types as $type)
                            <option value="{{ $type }}" {{ $f['club_type'] === $type ? 'selected' : '' }}>{{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label for="f-country" class="small text-muted mb-1">Country</label>
                    <select id="f-country" name="country" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach($countries as $cc)
                            <option value="{{ $cc }}" {{ $f['country'] === $cc ? 'selected' : '' }}>{{ $cc }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-from" class="small text-muted mb-1">Created from</label>
                    <input id="f-from" type="date" name="created_from" value="{{ $f['created_from'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f-to" class="small text-muted mb-1">Created to</label>
                    <input id="f-to" type="date" name="created_to" value="{{ $f['created_to'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 d-flex flex-wrap gap-3 mt-2">
                    @foreach(['has_active_competition' => 'Live competition', 'has_pending_photos' => 'Open photo submissions', 'needs_owner' => 'Needs owner'] as $key => $label)
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="{{ $key }}" value="1" id="f-{{ $key }}" {{ $f[$key] ? 'checked' : '' }}>
                            <label class="form-check-label small" for="f-{{ $key }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-end mb-2 gap-3 flex-wrap" data-section="results">
    <h2 class="h6 mb-0">
        @if($clubs->total() > 0)
            Showing {{ $clubs->firstItem() }}–{{ $clubs->lastItem() }} of {{ $clubs->total() }} {{ $clubs->total() === 1 ? 'club' : 'clubs' }}
        @else
            0 clubs
        @endif
        @if($isFiltered)<span class="text-muted small fw-normal">matching the current filters</span>@endif
    </h2>
    @if($isFiltered)
        <a href="{{ route('admin.clubs.index') }}" class="btn btn-link btn-sm p-0">Clear filters</a>
    @endif
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>Club</th>
                    <th>Location</th>
                    <th>Sport · Type</th>
                    <th>Status</th>
                    <th class="text-end">Members</th>
                    <th class="text-end">Packs</th>
                    <th class="text-end" title="Published competitions that have not ended (live + upcoming)">Comps</th>
                    <th class="text-end" title="Open photo submissions">Photos</th>
                    <th>Owner</th>
                    <th title="Last join or play">Activity</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($clubs as $club)
                @php $showUrl = route('admin.clubs.show', $club); @endphp
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            @include('admin.clubs._logo', ['club' => $club])
                            <div>
                                <a href="{{ $showUrl }}" class="club-name">{{ $club->name }}</a>
                                <div class="text-muted small font-monospace">{{ $club->slug }} · #{{ $club->id }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="small">{{ $club->city ?: '—' }}@if($club->country_code)<span class="text-muted">, {{ $club->country_code }}</span>@endif</td>
                    <td class="small">{{ $club->sport ? $club->sport->emoji . ' ' . $club->sport->name : '—' }}<div class="text-muted">{{ $typeLabels[$club->club_type->value] ?? $club->club_type->value }}</div></td>
                    <td>@include('admin.clubs._status', ['club' => $club])</td>
                    <td class="text-end small">{{ number_format($club->members_count) }}</td>
                    <td class="text-end small">{{ $club->active_packs_count }}</td>
                    <td class="text-end small">
                        @if($club->live_competitions_count > 0)<span class="badge badge-active">{{ $club->live_competitions_count }}</span>@else<span class="text-muted">—</span>@endif
                    </td>
                    <td class="text-end small">
                        @if($club->open_photos_count > 0)<span class="badge badge-soft-warn">{{ $club->open_photos_count }}</span>@else<span class="text-muted">—</span>@endif
                    </td>
                    <td class="small">
                        @forelse($club->owners as $owner)
                            <div>{{ $owner->user?->username ?? '—' }}</div>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </td>
                    <td class="small text-muted text-nowrap" title="{{ $club->last_activity_at?->toDateTimeString() }}">{{ $club->last_activity_at?->diffForHumans(short: true) ?? 'never' }}</td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Actions for {{ $club->name }}">
                            <a href="{{ $showUrl }}" class="btn btn-outline-secondary">View</a>
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $club->name }}">
                                <span aria-hidden="true">…</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('admin.clubs.edit', $club) }}">Edit details</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'members']) }}">Members</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'codes']) }}">Invite codes</a></li>
                                <li><hr class="dropdown-divider"></li>
                                @if($club->status->value === 'active')
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="suspend" data-url="{{ route('admin.clubs.suspend', $club) }}" data-club="{{ $club->name }}">Suspend…</button></li>
                                @else
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="reactivate" data-url="{{ route('admin.clubs.reactivate', $club) }}" data-club="{{ $club->name }}">Reactivate…</button></li>
                                @endif
                                @if($club->status->value !== 'archived')
                                    <li><button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#club-status-modal" data-action="archive" data-url="{{ route('admin.clubs.archive', $club) }}" data-club="{{ $club->name }}">Archive…</button></li>
                                @endif
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center text-muted py-5">
                        @if($isFiltered)
                            <div class="fw-semibold text-body mb-1">No clubs match these filters<span>@if($f['q'] !== '') for &ldquo;{{ $f['q'] }}&rdquo;@endif</span>.</div>
                            <a href="{{ route('admin.clubs.index') }}">Clear filters</a>
                        @else
                            <div class="fw-semibold text-body mb-1">No clubs yet.</div>
                            Clubs are created by BallPicker admins. <a href="{{ route('admin.clubs.create') }}">Create the first club.</a>
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($clubs->hasPages())
    <div class="mt-3">{{ $clubs->links('pagination::bootstrap-5') }}</div>
@endif

@include('admin.clubs._status_modals')
@endsection
