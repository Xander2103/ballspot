<div class="d-flex justify-content-between align-items-start mb-2 gap-3">
    <div class="small text-muted">
        Club packs are private to members. They can mix this club's own challenges with BallPicker challenges, never another club's.
        Only <strong>active</strong> packs with ready challenges can be played.
    </div>
    <a href="{{ route('admin.clubs.packs.create', $club) }}" class="btn btn-primary btn-sm text-nowrap">+ New club pack</a>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>Pack</th>
                    <th>Sport</th>
                    <th>Status</th>
                    <th class="text-end">Challenges</th>
                    <th class="text-end" title="Completed normal-track attempts">Completions</th>
                    <th class="text-end">Competitions</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($packs as $pack)
                <tr>
                    <td>
                        <a href="/admin/packs/{{ $pack->id }}/edit" class="club-name">{{ $pack->name }}</a>
                        <div class="text-muted small font-monospace">{{ $pack->slug }}</div>
                    </td>
                    <td class="small">{{ $pack->sport ? $pack->sport->emoji . ' ' . $pack->sport->name : 'Global' }}</td>
                    <td><span class="badge badge-{{ $pack->status }}">{{ ucfirst($pack->status) }}</span>@if($pack->completion_badge_id) <span title="Awards a club trophy" aria-label="Awards a club trophy">🏆</span>@endif</td>
                    <td class="text-end small">{{ $pack->challenges_count }}</td>
                    <td class="text-end small">{{ $pack->completions_count }}</td>
                    <td class="text-end small">{{ $pack->competitions_count ?: '—' }}</td>
                    <td class="text-end"><a href="/admin/packs/{{ $pack->id }}/edit" class="btn btn-outline-secondary btn-sm">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No club packs yet. <a href="{{ route('admin.clubs.packs.create', $club) }}">Create the first one.</a></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($packs->hasPages())<div class="mt-3">{{ $packs->links('pagination::bootstrap-5') }}</div>@endif
<p class="small text-muted mt-2 mb-0">Club challenges are managed under <a href="/admin/challenges?club={{ $club->id }}">Challenges → scope {{ $club->name }}</a>.</p>
