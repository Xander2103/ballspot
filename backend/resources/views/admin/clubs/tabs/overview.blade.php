@php
    $m = $stats['members']; $p = $stats['plays']; $comp = $stats['competitions'];
    $tiles = [
        ['label' => 'Active members', 'value' => number_format($m['total']), 'hint' => 'of ' . number_format($m['capacity']) . ' max', 'url' => route('admin.clubs.show', ['club' => $club, 'tab' => 'members'])],
        ['label' => 'Seen last 7 days', 'value' => number_format($m['active_7d']), 'hint' => number_format($m['active_30d']) . ' in 30 days', 'url' => null],
        ['label' => 'Joins · leaves (30d)', 'value' => '+' . $m['joins_30d'] . ' · −' . $m['leaves_30d'], 'hint' => $m['banned'] . ' banned', 'url' => null],
        ['label' => 'Plays last 7 days', 'value' => number_format($p['plays_7d']), 'hint' => number_format($p['plays_30d']) . ' in 30 days · ' . $p['players_30d'] . ' players', 'url' => null],
        ['label' => 'Pack completions', 'value' => number_format($p['pack_completions']), 'hint' => $p['pack_completions_30d'] . ' in 30 days', 'url' => route('admin.clubs.show', ['club' => $club, 'tab' => 'packs'])],
        ['label' => 'Live competitions', 'value' => $comp['live_count'], 'hint' => $comp['upcoming'] . ' upcoming · ' . $comp['drafts'] . ' draft', 'url' => route('admin.clubs.show', ['club' => $club, 'tab' => 'competitions'])],
        ['label' => 'Open photos', 'value' => $stats['photos']['open'], 'hint' => $stats['photos']['oldest'] ? 'oldest ' . \Illuminate\Support\Carbon::parse($stats['photos']['oldest'])->diffForHumans() : 'queue empty', 'url' => route('admin.clubs.show', ['club' => $club, 'tab' => 'photos'])],
        ['label' => 'Usable invite codes', 'value' => $stats['codes']['usable'], 'hint' => $club->join_enabled ? 'joining enabled' : 'joining switched off', 'url' => route('admin.clubs.show', ['club' => $club, 'tab' => 'codes'])],
    ];
@endphp
<div class="row g-2 mb-4" data-section="overview-stats">
    @foreach($tiles as $tile)
        <div class="col-6 col-md-3">
            @if($tile['url'])<a href="{{ $tile['url'] }}" class="admin-stat h-100">@else<div class="admin-stat h-100">@endif
                <div class="admin-stat-value">{{ $tile['value'] }}</div>
                <div class="admin-stat-label">{{ $tile['label'] }}</div>
                <div class="text-muted" style="font-size:.7rem">{{ $tile['hint'] }}</div>
            @if($tile['url'])</a>@else</div>@endif
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Recent activity</h2>
                <a href="{{ route('admin.clubs.show', ['club' => $club, 'tab' => 'audit']) }}" class="small">Full audit log</a>
            </div>
            @include('admin.clubs._audit_table', ['events' => $stats['recent_events'], 'auditFiltered' => false])
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Live competitions</h2></div>
            <ul class="list-group list-group-flush">
                @forelse($comp['live'] as $c)
                    <li class="list-group-item small d-flex justify-content-between">
                        <span class="fw-semibold">{{ $c->name }}</span>
                        <span class="text-muted">{{ $c->participants_count }} players · ends {{ $c->ends_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="list-group-item small text-muted">No competition is live right now.</li>
                @endforelse
            </ul>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Club facts</h2></div>
            <div class="card-body">
                <dl class="club-facts row mb-0 small">
                    <dt class="col-5">Owners</dt>
                    <dd class="col-7">@forelse($club->owners as $o){{ $o->user?->username }}@if(!$loop->last), @endif @empty<span class="text-danger">none</span>@endforelse</dd>
                    <dt class="col-5">Admins</dt><dd class="col-7">{{ $m['admins'] }}</dd>
                    <dt class="col-5">Packs</dt><dd class="col-7">{{ $stats['packs']['active'] }} active of {{ $stats['packs']['total'] }}</dd>
                    <dt class="col-5">Timezone</dt><dd class="col-7">{{ $club->timezone }}</dd>
                    <dt class="col-5">Member list</dt><dd class="col-7">{{ $club->setting('member_list_visibility') === 'members' ? 'visible to members' : 'owners & admins only' }}</dd>
                    <dt class="col-5">Last activity</dt><dd class="col-7">{{ $club->last_activity_at?->diffForHumans() ?? 'never' }}</dd>
                    @if($club->contact_name || $club->contact_email)
                        <dt class="col-5">Contact</dt><dd class="col-7">{{ $club->contact_name }}@if($club->contact_email)<br><a href="mailto:{{ $club->contact_email }}">{{ $club->contact_email }}</a>@endif</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>
