@extends('admin.layout')

@section('title', 'Packs')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Challenge Packs</h1>
    <a href="/admin/packs/create{{ $scopeClub ? '?club=' . $scopeClub->id : '' }}" class="btn btn-primary btn-sm">{{ $scopeClub ? '+ New club pack' : '+ New Pack' }}</a>
</div>

@if($scope === '')
<p class="text-muted small mb-3">
    Curated content collections (e.g. Belgium Pack, Easy Starter Pack). Content only — no prices,
    no purchases. Only <strong>active</strong> + <strong>public</strong> packs appear in the app.
</p>
@else
<div class="alert alert-info py-2 small mb-3" data-section="club-scope">
    <span class="badge badge-club">Club packs</span>
    @if($scopeClub)
        Packs of <a href="{{ route('admin.clubs.show', $scopeClub) }}" class="fw-semibold">{{ $scopeClub->name }}</a>.
    @else
        Packs of all clubs.
    @endif
    Private to club members; never in the public catalogue. They may contain the club's own challenges and BallPicker challenges.
</div>
@endif

<form method="GET" action="/admin/packs" class="row g-2 align-items-center mb-3" data-section="filters">
    <div class="col-auto">
        <label for="f-club" class="visually-hidden">Scope</label>
        <select id="f-club" name="club" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Scope: BallPicker</option>
            <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>Club: all clubs ({{ $clubPackCount }})</option>
            @foreach($clubOptions as $opt)
                <option value="{{ $opt->id }}" {{ $scope === (string) $opt->id ? 'selected' : '' }}>Club: {{ $opt->name }}</option>
            @endforeach
        </select>
    </div>
    <noscript><div class="col-auto"><button class="btn btn-secondary btn-sm">Apply</button></div></noscript>
    @if($scope !== '')<div class="col-auto"><a href="/admin/packs" class="btn btn-link btn-sm">Back to BallPicker packs</a></div>@endif
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">Order</th>
                    <th>Name</th>
                    <th>Owner</th>
                    <th>Sport</th>
                    <th>Challenges</th>
                    <th>Status</th>
                    <th>Visibility</th>
                    <th>Featured</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($packs as $pack)
                <tr>
                    <td class="text-muted small">{{ $pack->sort_order }}</td>
                    <td>
                        <div class="fw-semibold">{{ $pack->name }}</div>
                        <div class="text-muted small font-monospace">{{ $pack->slug }}</div>
                    </td>
                    <td class="small">
                        @if($pack->club_id)
                            <a href="{{ route('admin.clubs.show', ['club' => $pack->club_id, 'tab' => 'packs']) }}" class="badge badge-club text-decoration-none" title="Private club pack">Club: {{ $pack->club?->name ?? '#' . $pack->club_id }}</a>
                        @else
                            <span class="text-muted">BallPicker</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $pack->sport->name ?? 'Global' }}</td>
                    <td class="small">
                        {{ $pack->challenges_count }}
                        @if($pack->status === 'active' && $pack->readyChallenges()->isEmpty())
                            <span class="badge bg-warning text-dark ms-1" title="Active but no ready challenges">⚠ no ready</span>
                        @endif
                    </td>
                    <td>
                        @if($pack->status === 'active')
                            <span class="badge badge-active">Active</span>
                        @elseif($pack->status === 'archived')
                            <span class="badge badge-archived">Archived</span>
                        @else
                            <span class="badge badge-draft">Draft</span>
                        @endif
                    </td>
                    <td class="small">{{ ucfirst($pack->visibility) }}</td>
                    <td class="small">{{ $pack->is_featured ? '★' : '—' }}</td>
                    <td>
                        <a href="/admin/packs/{{ $pack->id }}/edit" class="btn btn-outline-secondary btn-sm">Edit</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        @if($scope === '')
                            No packs yet. <a href="/admin/packs/create">Create one.</a>
                        @else
                            No club packs{{ $scopeClub ? ' for ' . $scopeClub->name : '' }} yet.
                            @if($scopeClub)<a href="/admin/packs/create?club={{ $scopeClub->id }}">Create one.</a>@endif
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $packs->links() }}</div>
</div>
@endsection
