{{-- Standings tab. Expects $standingsRows (paginator of rows), $attemptsByUser, $rankedCount, $canVoidAttempt. --}}
@php $tz = $competition->timezone; @endphp

@if($preview = session('rebuild_preview'))
<div class="card border-info shadow-sm mb-3" data-section="rebuild-preview">
    <div class="card-body py-3">
        <h2 class="h6 mb-1">Rebuild preview (dry run)</h2>
        <p class="small mb-2">{{ $preview['players'] }} players checked · <strong>{{ $preview['count'] }} {{ $preview['count'] === 1 ? 'difference' : 'differences' }}</strong> between the stored standings and a rebuild from attempts and guesses.</p>
        @if($preview['changes'])
            <ul class="small font-monospace mb-2">
                @foreach($preview['changes'] as $c)
                    <li>{{ $c['type'] }} · user {{ $c['user_id'] ?? '—' }} · pack {{ $c['pack_id'] ?? '—' }} · {{ $c['field'] }}: {{ json_encode($c['from']) }} → {{ json_encode($c['to']) }}</li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('admin.clubs.competitions.rebuild', [$club, $competition]) }}" class="d-inline">@csrf
            <input type="hidden" name="dry_run" value="0">
            <button type="submit" class="btn btn-sm btn-primary">Apply rebuild{{ $competition->phase()->value === 'finalized' ? ' and re-finalize' : '' }}</button>
        </form>
    </div>
</div>
@endif

<div class="d-flex justify-content-end mb-2">
    <a href="{{ route('admin.clubs.competitions.standings-csv', [$club, $competition]) }}" class="btn btn-outline-secondary btn-sm" data-section="standings-csv">Download CSV</a>
</div>
<div class="small text-muted mb-2">{{ $rankedCount }} ranked {{ $rankedCount === 1 ? 'player' : 'players' }}. Ranking: total score → reached first → fewer attempts; equal = shared rank. Players who left or were removed are hidden from members (kept here).</div>
<div class="card shadow-sm" data-section="standings">
    <div class="table-responsive">
        <table class="table table-clubs mb-0">
            <thead class="table-light">
                <tr>
                    <th class="text-end">Rank</th>
                    <th>Player</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Packs</th>
                    <th class="text-end">Attempts</th>
                    <th>Reached at ({{ $tz }})</th>
                    <th>Attempt details</th>
                </tr>
            </thead>
            <tbody>
            @forelse($standingsRows as $row)
                <tr>
                    <td class="text-end fw-semibold">{{ $row['rank'] ?? '—' }}</td>
                    <td>{{ $row['username'] }}@if($row['member_status'] !== 'active') <span class="badge badge-archived">{{ $row['member_status'] }} · hidden</span>@endif</td>
                    <td class="text-end">{{ number_format($row['total_score']) }}</td>
                    <td class="text-end small">{{ $row['packs_completed'] }} done · {{ $row['packs_counted'] }} counted</td>
                    <td class="text-end small">{{ $row['attempts_used'] }}</td>
                    <td class="small text-nowrap">{{ $row['score_reached_at'] ? \Carbon\Carbon::parse($row['score_reached_at'], 'UTC')->setTimezone($tz)->format('Y-m-d H:i:s') : '—' }}</td>
                    <td class="small">
                        <details>
                            <summary>{{ \Illuminate\Support\Str::plural('attempt', ($attemptsByUser[$row['user_id']] ?? collect())->count(), true) }}</summary>
                            <table class="table table-sm mb-0 mt-1">
                                @foreach($attemptsByUser[$row['user_id']] ?? [] as $a)
                                    <tr data-attempt="{{ $a->id }}">
                                        <td>{{ $a->pack?->name }} #{{ $a->attempt_number }}</td>
                                        <td><span class="badge badge-soft">{{ $a->status->value }}</span>@if($a->is_rewarded_run) <span title="Holds the global reward">★</span>@endif</td>
                                        <td class="text-end">{{ $a->total_score }}</td>
                                        <td>
                                            @if($a->status->value === 'voided')
                                                <span class="text-muted">{{ $a->void_reason }}</span>
                                            @elseif($canVoidAttempt)
                                                <form method="POST" action="{{ route('admin.clubs.competitions.attempts.void', [$club, $competition, $a]) }}" class="input-group input-group-sm">@csrf
                                                    <input type="text" name="reason" required maxlength="200" class="form-control" placeholder="Reason" aria-label="Void reason">
                                                    <button class="btn btn-outline-danger" type="submit">Void attempt</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No scores yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if($standingsRows->hasPages())<div class="mt-3">{{ $standingsRows->links('pagination::bootstrap-5') }}</div>@endif
