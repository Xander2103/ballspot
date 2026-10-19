{{-- Audit events table. Expects $events (paginator or collection), optional $showClub (bool). --}}
@php $showClub = $showClub ?? false; @endphp
<div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle table-clubs">
        <thead class="table-light">
            <tr>
                <th>Time</th>
                @if($showClub)<th>Club</th>@endif
                <th>Actor</th>
                <th>Action</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        @forelse($events as $event)
            @php
                $meta = collect($event->metadata ?? [])->map(function ($v, $k) {
                    $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
                    return $k . ': ' . \Illuminate\Support\Str::limit($v, 120);
                });
            @endphp
            <tr>
                <td class="text-nowrap small" title="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                @if($showClub)
                    <td class="small">
                        @if($event->club)
                            <a href="{{ route('admin.clubs.show', ['club' => $event->club_id, 'tab' => 'audit']) }}">{{ $event->club->name }}</a>
                        @else
                            <span class="text-muted">deleted club</span>
                        @endif
                    </td>
                @endif
                <td class="small">
                    {{ $event->actor?->username ?? ($event->actor_kind === 'system' ? 'system' : '—') }}
                    <div class="text-muted" style="font-size:.7rem">{{ str_replace('_', ' ', $event->actor_kind) }}</div>
                </td>
                <td class="small"><span class="fw-semibold">{{ $event->label() }}</span><div class="text-muted font-monospace" style="font-size:.7rem">{{ $event->action }}</div></td>
                <td class="small text-muted" style="max-width: 32rem;">
                    @if($meta->isEmpty())—@else{{ $meta->join(' · ') }}@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $showClub ? 5 : 4 }}" class="text-center text-muted py-4">No audit events{{ ($auditFiltered ?? false) ? ' match these filters' : ' yet' }}.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
