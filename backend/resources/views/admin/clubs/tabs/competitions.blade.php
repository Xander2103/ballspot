@php
    $phaseClass = ['draft' => 'badge-draft', 'upcoming' => 'badge-scheduled', 'active' => 'badge-active', 'ended' => 'badge-soft-warn', 'finalized' => 'badge-ok', 'cancelled' => 'badge-archived', 'voided' => 'badge-archived'];
@endphp
<div class="d-flex justify-content-between align-items-start mb-2 gap-3">
    <div class="small text-muted">
        Private competitions on this club's packs. Replay rules, packs and the start time lock automatically when a competition starts.
        <a href="{{ route('admin.club-competitions.index', ['club' => $club->id]) }}">All club competitions</a>
    </div>
    <a href="{{ route('admin.clubs.competitions.create', $club) }}" class="btn btn-primary btn-sm text-nowrap">+ New competition</a>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th>Competition</th>
                    <th>Phase</th>
                    <th>Window ({{ $club->timezone }})</th>
                    <th>Replay rules</th>
                    <th class="text-end">Packs</th>
                    <th class="text-end">Players</th>
                </tr>
            </thead>
            <tbody>
            @forelse($competitions as $c)
                @php $phase = $c->phase()->value; @endphp
                <tr>
                    <td><a href="{{ route('admin.clubs.competitions.show', [$club, $c]) }}" class="fw-semibold club-name">{{ $c->name }}</a><div class="text-muted small font-monospace">{{ $c->public_id }}</div></td>
                    <td><span class="badge {{ $phaseClass[$phase] ?? 'badge-soft' }}">{{ ucfirst($phase) }}</span>@if($c->isLocked() && $phase !== 'draft') <span title="Rules locked">🔒</span>@endif</td>
                    <td class="small text-nowrap">
                        {{ $c->starts_at?->copy()->setTimezone($club->timezone)->format('Y-m-d H:i') }}
                        – {{ $c->ends_at?->copy()->setTimezone($club->timezone)->format('Y-m-d H:i') }}
                    </td>
                    <td class="small">
                        {{ str_replace('_', ' ', $c->replay_mode->value) }}@if($c->replay_mode->value === 'multiple_attempts') · max {{ $c->max_attempts }} · {{ $c->score_rule->value }} counts @endif
                        <div class="text-muted" style="font-size:.7rem">normal play {{ $c->normal_play_policy->value }}</div>
                    </td>
                    <td class="text-end small">{{ $c->competition_packs_count }}</td>
                    <td class="text-end small">{{ $c->participants_count }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No competitions yet. <a href="{{ route('admin.clubs.competitions.create', $club) }}">Create the first one.</a></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($competitions->hasPages())<div class="mt-3">{{ $competitions->links('pagination::bootstrap-5') }}</div>@endif
